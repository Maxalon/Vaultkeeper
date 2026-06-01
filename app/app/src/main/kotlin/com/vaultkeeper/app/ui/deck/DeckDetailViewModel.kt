package com.vaultkeeper.app.ui.deck

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.api.dto.DeckDetailResponse
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.IllegalityDto
import com.vaultkeeper.app.data.repository.DeckRepository
import kotlinx.coroutines.async
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class DeckTab { MAINBOARD, ANALYSIS, ILLEGALITIES, PHYSICAL, WANTED }

data class DeckDetailUiState(
    val isLoading: Boolean = true,
    val deck: DeckDetailResponse? = null,
    val entries: List<DeckEntryDto> = emptyList(),
    val illegalities: List<IllegalityDto> = emptyList(),
    // scryfall_id_1 values of non-ignored card-level illegalities
    val illegalScryfallIds: Set<String> = emptySet(),
    val selectedTab: DeckTab = DeckTab.MAINBOARD,
    val selectedEntryId: Long? = null,
    val showDeckInfoSheet: Boolean = false,
    val error: String? = null,
)

class DeckDetailViewModel(
    private val repository: DeckRepository,
) : ViewModel() {

    private var deckId: Long? = null

    private val _uiState = MutableStateFlow(DeckDetailUiState())
    val uiState: StateFlow<DeckDetailUiState> = _uiState.asStateFlow()

    fun init(id: Long) {
        if (deckId == id) return
        deckId = id
        load()
    }

    private fun load() {
        val id = deckId ?: return
        viewModelScope.launch {
            _uiState.update { it.copy(isLoading = true, error = null) }
            try {
                val (deck, entries) = coroutineScope {
                    val deferredDeck = async { repository.getDeck(id) }
                    val deferredEntries = async { repository.getEntries(id) }
                    deferredDeck.await() to deferredEntries.await()
                }
                _uiState.update { it.copy(isLoading = false, deck = deck, entries = entries) }
            } catch (e: Exception) {
                _uiState.update { it.copy(isLoading = false, error = e.message ?: "Unknown error") }
            }
            // Illegalities load in the background — a failure here does not
            // surface an error; the badge simply stays absent.
            launch {
                runCatching {
                    val illegalities = repository.getIllegalities(id)
                    val illegalIds = illegalities
                        .filter { !it.ignored && CARD_LEVEL_TYPES.contains(it.type) }
                        .mapNotNull { it.scryfall_id_1 }
                        .toSet()
                    _uiState.update { it.copy(illegalities = illegalities, illegalScryfallIds = illegalIds) }
                }
            }
        }
    }

    fun selectTab(tab: DeckTab) = _uiState.update { it.copy(selectedTab = tab) }

    fun selectEntry(entryId: Long?) = _uiState.update { it.copy(selectedEntryId = entryId) }

    fun toggleDeckInfo() = _uiState.update { it.copy(showDeckInfoSheet = !it.showDeckInfoSheet) }

    fun retry() = load()

    companion object {
        // These types link to a specific card; deck-level types (e.g. deck_size)
        // do not have a meaningful scryfall_id_1 to highlight on an entry row.
        private val CARD_LEVEL_TYPES = setOf(
            "banned_card",
            "color_identity_violation",
            "duplicate_card",
            "not_legal_in_format",
            "invalid_companion",
        )
    }
}

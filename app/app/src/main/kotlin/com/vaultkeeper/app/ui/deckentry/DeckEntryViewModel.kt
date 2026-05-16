package com.vaultkeeper.app.ui.deckentry

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.api.dto.CommanderDto
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.ScryfallCardDto
import com.vaultkeeper.app.data.deck.DeckRepository
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class DeckEntryUiState(
    val deckName: String = "",
    val format: String = "",
    val entries: List<DeckEntryDto> = emptyList(),
    val commander1: CommanderDto? = null,
    val isLoading: Boolean = false,
    val searchResults: List<ScryfallCardDto> = emptyList(),
    val isSearching: Boolean = false,
)

class DeckEntryViewModel(
    private val deckId: Long,
    private val repo: DeckRepository,
) : ViewModel() {

    private val _uiState = MutableStateFlow(DeckEntryUiState(isLoading = true))
    val uiState: StateFlow<DeckEntryUiState> = _uiState.asStateFlow()

    private val _errorEvent = MutableSharedFlow<String>()
    val errorEvent: SharedFlow<String> = _errorEvent.asSharedFlow()

    init {
        load()
    }

    private fun load() {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true)
            val deckResult = repo.getDeck(deckId)
            val entriesResult = repo.listEntries(deckId)

            val deck = deckResult.getOrNull()
            val entries = entriesResult.getOrNull() ?: emptyList()

            _uiState.value = DeckEntryUiState(
                deckName = deck?.name ?: "",
                format = deck?.format ?: "",
                entries = entries,
                commander1 = deck?.commander1,
                isLoading = false,
            )

            if (deckResult.isFailure || entriesResult.isFailure) {
                _errorEvent.emit("Failed to load deck")
            }
        }
    }

    // ── Add card ─────────────────────────────────────────────────────────────

    fun addCard(scryfallId: String, zone: String = "main") {
        viewModelScope.launch {
            repo.addEntry(deckId, scryfallId, zone)
                .onSuccess { newEntry ->
                    _uiState.value = _uiState.value.copy(
                        entries = _uiState.value.entries + newEntry,
                    )
                }
                .onFailure {
                    _errorEvent.emit("Failed to add card")
                }
        }
    }

    // ── Quantity stepper ─────────────────────────────────────────────────────

    fun increment(entry: DeckEntryDto) {
        val newQty = entry.quantity + 1
        applyQuantityUpdate(entry, newQty)
    }

    fun decrement(entry: DeckEntryDto) {
        if (entry.quantity <= 1) return  // caller handles the remove-confirmation flow
        val newQty = entry.quantity - 1
        applyQuantityUpdate(entry, newQty)
    }

    private fun applyQuantityUpdate(entry: DeckEntryDto, newQty: Int) {
        val optimistic = entry.copy(quantity = newQty)
        updateEntryInList(optimistic)

        viewModelScope.launch {
            repo.updateEntryQuantity(deckId, entry.id, newQty)
                .onSuccess { updated ->
                    updateEntryInList(updated)
                }
                .onFailure {
                    updateEntryInList(entry)  // revert
                    _errorEvent.emit("Failed to update quantity")
                }
        }
    }

    // ── Remove entry ─────────────────────────────────────────────────────────

    fun removeEntry(entry: DeckEntryDto) {
        val snapshot = _uiState.value.entries
        _uiState.value = _uiState.value.copy(
            entries = snapshot.filterNot { it.id == entry.id },
        )

        viewModelScope.launch {
            repo.deleteEntry(deckId, entry.id)
                .onFailure {
                    _uiState.value = _uiState.value.copy(entries = snapshot)  // revert
                    _errorEvent.emit("Failed to remove card")
                }
        }
    }

    // ── Zone move ────────────────────────────────────────────────────────────

    fun moveToZone(entry: DeckEntryDto, newZone: String) {
        if (entry.zone == newZone) return
        val optimistic = entry.copy(zone = newZone)
        updateEntryInList(optimistic)

        viewModelScope.launch {
            repo.updateEntryZone(deckId, entry.id, newZone)
                .onSuccess { updated ->
                    updateEntryInList(updated)
                }
                .onFailure {
                    updateEntryInList(entry)  // revert
                    _errorEvent.emit("Failed to move card")
                }
        }
    }

    // ── Commander assignment ─────────────────────────────────────────────────

    fun setCommander(scryfallId: String) {
        viewModelScope.launch {
            repo.setCommander(deckId, scryfallId)
                .onSuccess { updated ->
                    _uiState.value = _uiState.value.copy(
                        commander1 = updated.commander1,
                        // Reload entries because syncCommanderEntries may have added/updated entries.
                        entries = repo.listEntries(deckId).getOrElse { _uiState.value.entries },
                    )
                }
                .onFailure {
                    _errorEvent.emit("Failed to set commander")
                }
        }
    }

    // ── Card search ──────────────────────────────────────────────────────────

    fun searchCards(query: String) {
        if (query.isBlank()) {
            _uiState.value = _uiState.value.copy(searchResults = emptyList(), isSearching = false)
            return
        }
        _uiState.value = _uiState.value.copy(isSearching = true)
        viewModelScope.launch {
            repo.searchCards(query)
                .onSuccess { cards ->
                    _uiState.value = _uiState.value.copy(
                        searchResults = cards,
                        isSearching = false,
                    )
                }
                .onFailure {
                    _uiState.value = _uiState.value.copy(isSearching = false)
                    _errorEvent.emit("Search failed")
                }
        }
    }

    fun clearSearch() {
        _uiState.value = _uiState.value.copy(searchResults = emptyList(), isSearching = false)
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private fun updateEntryInList(updated: DeckEntryDto) {
        _uiState.value = _uiState.value.copy(
            entries = _uiState.value.entries.map { if (it.id == updated.id) updated else it },
        )
    }
}

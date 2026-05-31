package com.vaultkeeper.app.ui.deck

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.api.DeckApi
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.MoveZoneRequest
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

enum class DeckZone(val apiValue: String, val label: String) {
    MAIN("main", "Main"),
    SIDE("side", "Sideboard"),
    MAYBE("maybe", "Maybe"),
    ;

    companion object {
        fun from(apiValue: String): DeckZone =
            entries.first { it.apiValue == apiValue }

        val all: List<DeckZone> = entries.toList()
    }
}

data class DeckEntriesState(
    val entries: List<DeckEntryDto> = emptyList(),
    val isLoading: Boolean = false,
    val error: String? = null,
)

class DeckEntriesViewModel(
    private val api: DeckApi,
    private val deckId: Long,
) : ViewModel() {

    private val _state = MutableStateFlow(DeckEntriesState())
    val state: StateFlow<DeckEntriesState> = _state.asStateFlow()

    init {
        loadEntries()
    }

    fun loadEntries() {
        viewModelScope.launch {
            _state.update { it.copy(isLoading = true, error = null) }
            try {
                val entries = api.getEntries(deckId)
                _state.update { it.copy(entries = entries, isLoading = false) }
            } catch (e: Exception) {
                _state.update { it.copy(isLoading = false, error = e.message) }
            }
        }
    }

    fun moveEntryToZone(entryId: Long, zone: DeckZone) {
        viewModelScope.launch {
            try {
                val updated = api.updateEntry(deckId, entryId, MoveZoneRequest(zone = zone.apiValue))
                _state.update { s ->
                    s.copy(entries = s.entries.map { if (it.id == entryId) updated else it })
                }
            } catch (e: Exception) {
                _state.update { it.copy(error = e.message) }
            }
        }
    }
}

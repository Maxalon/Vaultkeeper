package com.vaultkeeper.app.ui.deck

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.deck.DeckEntryRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

class DeckEntryListViewModel(private val repo: DeckEntryRepository) : ViewModel() {

    private val _entries = MutableStateFlow<List<DeckEntryDto>>(emptyList())
    val entries: StateFlow<List<DeckEntryDto>> = _entries.asStateFlow()

    private val _error = MutableStateFlow<String?>(null)
    val error: StateFlow<String?> = _error.asStateFlow()

    fun load(deckId: Int) {
        viewModelScope.launch {
            runCatching { repo.getEntries(deckId) }
                .onSuccess { _entries.value = it }
                .onFailure { _error.value = it.message }
        }
    }

    fun moveToZone(deckId: Int, entryId: Int, newZone: String) {
        val previous = _entries.value.find { it.id == entryId } ?: return

        // Optimistic update — row reflects new zone immediately.
        _entries.update { list -> list.map { if (it.id == entryId) it.copy(zone = newZone) else it } }

        viewModelScope.launch {
            runCatching { repo.moveToZone(deckId, entryId, newZone) }
                .onSuccess { updated ->
                    _entries.update { list -> list.map { if (it.id == updated.id) updated else it } }
                }
                .onFailure {
                    // Restore previous zone on error.
                    _entries.update { list ->
                        list.map { if (it.id == entryId) previous else it }
                    }
                    _error.value = it.message
                }
        }
    }
}

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

    fun load(deckId: Int) {
        viewModelScope.launch {
            _entries.value = repo.getEntries(deckId)
        }
    }

    fun moveToZone(deckId: Int, entryId: Int, newZone: String) {
        viewModelScope.launch {
            val updated = repo.moveToZone(deckId, entryId, newZone)
            _entries.update { current ->
                current.map { if (it.id == updated.id) updated else it }
            }
        }
    }
}

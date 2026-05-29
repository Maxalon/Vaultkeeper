package com.vaultkeeper.app.ui.decklist

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.api.dto.DeckDto
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.repository.DeckRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class DeckListState(
    val decks: List<DeckDto> = emptyList(),
    val loading: Boolean = true,
    val error: String? = null,
    val showCreateSheet: Boolean = false,
    val createName: String = "",
    val createFormat: String = "commander",
    val creating: Boolean = false,
    val createError: String? = null,
    val pendingDelete: DeckDto? = null,
)

class DeckListViewModel(
    private val deckRepo: DeckRepository,
    private val auth: AuthRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(DeckListState())
    val state: StateFlow<DeckListState> = _state.asStateFlow()

    init { load() }

    fun load() {
        _state.update { it.copy(loading = true, error = null) }
        viewModelScope.launch {
            deckRepo.listDecks()
                .onSuccess { list -> _state.update { it.copy(loading = false, decks = list) } }
                .onFailure { e -> _state.update { it.copy(loading = false, error = e.message ?: "Load failed") } }
        }
    }

    fun openCreateSheet() =
        _state.update { it.copy(showCreateSheet = true, createName = "", createFormat = "commander", createError = null) }

    fun closeCreateSheet() = _state.update { it.copy(showCreateSheet = false) }

    fun onCreateNameChange(v: String) = _state.update { it.copy(createName = v, createError = null) }

    fun onCreateFormatChange(v: String) = _state.update { it.copy(createFormat = v) }

    fun submitCreate() {
        val s = _state.value
        if (s.createName.isBlank() || s.creating) return
        _state.update { it.copy(creating = true, createError = null) }
        viewModelScope.launch {
            deckRepo.createDeck(s.createName.trim(), s.createFormat)
                .onSuccess { deck ->
                    _state.update { it.copy(creating = false, showCreateSheet = false, decks = it.decks + deck) }
                }
                .onFailure { e ->
                    _state.update { it.copy(creating = false, createError = e.message ?: "Create failed") }
                }
        }
    }

    fun requestDelete(deck: DeckDto) = _state.update { it.copy(pendingDelete = deck) }

    fun cancelDelete() = _state.update { it.copy(pendingDelete = null) }

    fun confirmDelete() {
        val deck = _state.value.pendingDelete ?: return
        _state.update { it.copy(pendingDelete = null) }
        viewModelScope.launch {
            deckRepo.deleteDeck(deck.id)
                .onSuccess { _state.update { it.copy(decks = it.decks.filter { d -> d.id != deck.id }) } }
                .onFailure { e -> _state.update { it.copy(error = e.message ?: "Delete failed") } }
        }
    }

    fun logout() {
        viewModelScope.launch { auth.logout() }
    }
}

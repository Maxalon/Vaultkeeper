package com.vaultkeeper.app.ui.decklist

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.api.dto.DeckSummaryDto
import com.vaultkeeper.app.data.deck.DeckRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class DeckListUiState(
    val decks: List<DeckSummaryDto> = emptyList(),
    val isLoading: Boolean = false,
    val errorMessage: String? = null,
)

class DeckListViewModel(
    private val auth: AuthRepository,
    private val deckRepo: DeckRepository,
) : ViewModel() {

    private val _uiState = MutableStateFlow(DeckListUiState(isLoading = true))
    val uiState: StateFlow<DeckListUiState> = _uiState.asStateFlow()

    init {
        loadDecks()
    }

    fun loadDecks() {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            deckRepo.listDecks()
                .onSuccess { decks ->
                    _uiState.value = DeckListUiState(decks = decks)
                }
                .onFailure { error ->
                    _uiState.value = DeckListUiState(
                        errorMessage = error.message ?: "Failed to load decks",
                    )
                }
        }
    }

    fun logout() {
        viewModelScope.launch { auth.logout() }
    }
}

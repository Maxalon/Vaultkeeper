package com.vaultkeeper.app.ui.collection

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.api.dto.CollectionEntryDto
import com.vaultkeeper.app.data.api.dto.CollectionTotalsDto
import com.vaultkeeper.app.data.api.dto.LocationDto
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.repository.CollectionRepository
import kotlinx.coroutines.async
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class CollectionUiState(
    val loading: Boolean = true,
    val entries: List<CollectionEntryDto> = emptyList(),
    val locations: List<LocationDto> = emptyList(),
    val selectedLocationId: Int? = null,
    val totals: CollectionTotalsDto? = null,
    val searchQuery: String = "",
    val error: String? = null,
    val selectedEntryId: Int? = null,
    val currentPage: Int = 1,
    val hasMore: Boolean = false,
    val isLoadingMore: Boolean = false,
) {
    val filteredEntries: List<CollectionEntryDto>
        get() = if (searchQuery.isBlank()) entries
                else entries.filter {
                    it.card?.name?.contains(searchQuery, ignoreCase = true) == true
                }
}

class CollectionViewModel(
    private val repo: CollectionRepository,
    private val auth: AuthRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(CollectionUiState())

    val state = _state.stateIn(
        viewModelScope,
        SharingStarted.WhileSubscribed(5_000),
        CollectionUiState(),
    )

    init {
        load()
    }

    private fun load() {
        viewModelScope.launch {
            _state.update { it.copy(loading = true, error = null, entries = emptyList(), currentPage = 1, hasMore = false) }
            runCatching {
                val locationId = _state.value.selectedLocationId
                val locationsDeferred = async { repo.getLocations() }
                val entriesDeferred  = async { repo.getEntries(locationId, page = 1) }
                val totalsDeferred   = async { repo.getTotals(locationId) }
                Triple(locationsDeferred.await(), entriesDeferred.await(), totalsDeferred.await())
            }.onSuccess { (locs, entriesResponse, totals) ->
                _state.update {
                    it.copy(
                        loading = false,
                        locations = locs.locations,
                        entries = entriesResponse.data,
                        totals = totals,
                        currentPage = 1,
                        hasMore = entriesResponse.meta?.hasMore ?: false,
                    )
                }
            }.onFailure { err ->
                _state.update { it.copy(loading = false, error = err.message) }
            }
        }
    }

    fun loadMore() {
        val current = _state.value
        if (current.isLoadingMore || !current.hasMore || current.loading) return
        val nextPage = current.currentPage + 1
        viewModelScope.launch {
            _state.update { it.copy(isLoadingMore = true) }
            runCatching {
                repo.getEntries(current.selectedLocationId, page = nextPage)
            }.onSuccess { response ->
                _state.update {
                    it.copy(
                        isLoadingMore = false,
                        entries = it.entries + response.data,
                        currentPage = nextPage,
                        hasMore = response.meta?.hasMore ?: false,
                    )
                }
            }.onFailure {
                _state.update { it.copy(isLoadingMore = false) }
            }
        }
    }

    fun selectLocation(locationId: Int?) {
        _state.update { it.copy(selectedLocationId = locationId, searchQuery = "") }
        load()
    }

    fun updateSearch(query: String) {
        _state.update { it.copy(searchQuery = query) }
    }

    fun selectEntry(entryId: Int?) {
        _state.update { it.copy(selectedEntryId = entryId) }
    }

    fun logout() {
        viewModelScope.launch { auth.logout() }
    }

    fun refresh() {
        load()
    }
}

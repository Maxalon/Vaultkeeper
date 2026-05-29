package com.vaultkeeper.app.ui.settings

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.api.dto.PrivacySettingsPatch
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.privacy.PrivacyRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class SettingsState(
    val loading: Boolean = true,
    val collectionVisibility: String = "friends",
    val decksVisibility: String = "friends",
    val discoverable: Boolean = true,
    val saving: Boolean = false,
    val snackbar: String? = null,
)

class SettingsViewModel(
    private val auth: AuthRepository,
    private val privacy: PrivacyRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(SettingsState())
    val state: StateFlow<SettingsState> = _state.asStateFlow()

    init { load() }

    private fun load() {
        viewModelScope.launch {
            privacy.get()
                .onSuccess { dto ->
                    _state.update {
                        it.copy(
                            loading = false,
                            collectionVisibility = dto.collection_visibility,
                            decksVisibility = dto.decks_visibility,
                            discoverable = dto.discoverable,
                        )
                    }
                }
                .onFailure { _state.update { it.copy(loading = false) } }
        }
    }

    fun setCollectionVisibility(value: String) =
        patch(PrivacySettingsPatch(collection_visibility = value))

    fun setDecksVisibility(value: String) =
        patch(PrivacySettingsPatch(decks_visibility = value))

    fun setDiscoverable(value: Boolean) =
        patch(PrivacySettingsPatch(discoverable = value))

    private fun patch(p: PrivacySettingsPatch) {
        if (_state.value.saving) return
        _state.update { it.copy(saving = true) }
        viewModelScope.launch {
            privacy.patch(p)
                .onSuccess { dto ->
                    _state.update {
                        it.copy(
                            saving = false,
                            collectionVisibility = dto.collection_visibility,
                            decksVisibility = dto.decks_visibility,
                            discoverable = dto.discoverable,
                            snackbar = "Privacy settings saved.",
                        )
                    }
                }
                .onFailure { e ->
                    _state.update {
                        it.copy(
                            saving = false,
                            snackbar = e.message ?: "Failed to save privacy settings",
                        )
                    }
                }
        }
    }

    fun snackbarShown() = _state.update { it.copy(snackbar = null) }

    fun logout() {
        viewModelScope.launch { auth.logout() }
    }
}

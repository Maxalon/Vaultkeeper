package com.vaultkeeper.app.ui.login

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.auth.AuthRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

class LoginViewModel(private val auth: AuthRepository) : ViewModel() {

    private val _state = MutableStateFlow(LoginState())
    val state: StateFlow<LoginState> = _state.asStateFlow()

    fun onUsernameChange(value: String) =
        _state.update { it.copy(username = value, error = null) }

    fun onPasswordChange(value: String) =
        _state.update { it.copy(password = value, error = null) }

    fun submit() {
        val s = _state.value
        if (s.username.isBlank() || s.password.isBlank() || s.submitting) return

        _state.update { it.copy(submitting = true, error = null) }
        viewModelScope.launch {
            auth.login(s.username.trim(), s.password)
                .onFailure { e ->
                    _state.update { it.copy(submitting = false, error = e.message ?: "error") }
                }
                .onSuccess {
                    _state.update { it.copy(submitting = false) }
                }
        }
    }
}

data class LoginState(
    val username: String = "",
    val password: String = "",
    val submitting: Boolean = false,
    val error: String? = null,
)

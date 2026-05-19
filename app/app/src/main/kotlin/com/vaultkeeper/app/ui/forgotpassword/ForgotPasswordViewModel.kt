package com.vaultkeeper.app.ui.forgotpassword

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.auth.AuthRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

class ForgotPasswordViewModel(private val auth: AuthRepository) : ViewModel() {

    private val _state = MutableStateFlow(ForgotPasswordState())
    val state: StateFlow<ForgotPasswordState> = _state.asStateFlow()

    fun onEmailChange(value: String) = _state.update { it.copy(email = value, error = null) }

    fun submit() {
        val s = _state.value
        if (s.submitting || s.sent) return

        if (s.email.isBlank()) {
            _state.update { it.copy(error = "Email is required") }
            return
        }
        if (!EMAIL_REGEX.matches(s.email.trim())) {
            _state.update { it.copy(error = "Enter a valid email address") }
            return
        }

        _state.update { it.copy(submitting = true, error = null) }
        viewModelScope.launch {
            auth.forgotPassword(s.email.trim())
                .onFailure { e ->
                    _state.update { it.copy(submitting = false, error = e.message ?: "Request failed") }
                }
                .onSuccess {
                    _state.update { it.copy(submitting = false, sent = true) }
                }
        }
    }
}

private val EMAIL_REGEX = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")

data class ForgotPasswordState(
    val email: String = "",
    val submitting: Boolean = false,
    val sent: Boolean = false,
    val error: String? = null,
)

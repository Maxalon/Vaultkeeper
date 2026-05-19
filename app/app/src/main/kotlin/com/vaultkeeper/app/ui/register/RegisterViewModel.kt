package com.vaultkeeper.app.ui.register

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.auth.AuthRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

class RegisterViewModel(private val auth: AuthRepository) : ViewModel() {

    private val _state = MutableStateFlow(RegisterState())
    val state: StateFlow<RegisterState> = _state.asStateFlow()

    fun onUsernameChange(value: String) = _state.update { it.copy(username = value, error = null) }
    fun onEmailChange(value: String) = _state.update { it.copy(email = value, error = null) }
    fun onPasswordChange(value: String) = _state.update { it.copy(password = value, error = null) }
    fun onConfirmPasswordChange(value: String) = _state.update { it.copy(confirmPassword = value, error = null) }

    fun submit() {
        val s = _state.value
        if (s.submitting) return

        val validationError = validate(s)
        if (validationError != null) {
            _state.update { it.copy(error = validationError) }
            return
        }

        _state.update { it.copy(submitting = true, error = null) }
        viewModelScope.launch {
            auth.register(s.username.trim(), s.email.trim(), s.password, s.confirmPassword)
                .onFailure { e ->
                    _state.update { it.copy(submitting = false, error = e.message ?: "Registration failed") }
                }
                .onSuccess {
                    _state.update { it.copy(submitting = false) }
                }
        }
    }

    private fun validate(s: RegisterState): String? {
        if (s.username.isBlank()) return "Username is required"
        if (s.email.isBlank()) return "Email is required"
        if (!EMAIL_REGEX.matches(s.email.trim())) return "Enter a valid email address"
        if (s.password.length < 8) return "Password must be at least 8 characters"
        if (s.password != s.confirmPassword) return "Passwords do not match"
        return null
    }
}

private val EMAIL_REGEX = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")

data class RegisterState(
    val username: String = "",
    val email: String = "",
    val password: String = "",
    val confirmPassword: String = "",
    val submitting: Boolean = false,
    val error: String? = null,
)

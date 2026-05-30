package com.vaultkeeper.app.ui.onboarding

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.auth.AuthRepository
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

class OnboardingViewModel(private val auth: AuthRepository) : ViewModel() {

    private val _submitting = MutableStateFlow(false)
    val submitting: StateFlow<Boolean> = _submitting.asStateFlow()

    // extraBufferCapacity = 1: emit() never suspends given the double-tap guard
    // ensures at most one in-flight request at a time.
    private val _errors = MutableSharedFlow<String>(extraBufferCapacity = 1)
    val errors: SharedFlow<String> = _errors.asSharedFlow()

    fun complete() {
        if (_submitting.value) return
        _submitting.value = true
        viewModelScope.launch {
            val result = auth.completeOnboarding()
            _submitting.value = false
            result.onFailure { _errors.emit("Something went wrong. Please try again.") }
        }
    }
}

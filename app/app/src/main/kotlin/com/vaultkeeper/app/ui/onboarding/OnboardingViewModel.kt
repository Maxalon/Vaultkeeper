package com.vaultkeeper.app.ui.onboarding

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.auth.AuthRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

class OnboardingViewModel(private val auth: AuthRepository) : ViewModel() {

    private val _submitting = MutableStateFlow(false)
    val submitting: StateFlow<Boolean> = _submitting.asStateFlow()

    fun complete() {
        if (_submitting.value) return
        _submitting.value = true
        viewModelScope.launch {
            auth.completeOnboarding()
            _submitting.value = false
        }
    }
}

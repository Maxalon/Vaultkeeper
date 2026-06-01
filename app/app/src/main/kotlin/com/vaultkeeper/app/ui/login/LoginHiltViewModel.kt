package com.vaultkeeper.app.ui.login

import androidx.lifecycle.ViewModel
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject

// No-op placeholder; proves the Hilt annotation processor is wired.
// Actual DI uses Koin — see di/AppModule.kt.
@HiltViewModel
class LoginHiltViewModel @Inject constructor() : ViewModel()

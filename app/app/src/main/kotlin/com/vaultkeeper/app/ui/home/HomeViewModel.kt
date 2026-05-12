package com.vaultkeeper.app.ui.home

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.auth.AuthRepository
import kotlinx.coroutines.launch

class HomeViewModel(private val auth: AuthRepository) : ViewModel() {

    val session = auth.session

    fun logout() {
        viewModelScope.launch { auth.logout() }
    }
}

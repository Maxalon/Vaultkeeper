package com.vaultkeeper.app.data.auth

import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.asSharedFlow

class UnauthenticatedEvent {
    private val _flow = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    val flow: SharedFlow<Unit> = _flow.asSharedFlow()

    fun emit() {
        _flow.tryEmit(Unit)
    }
}

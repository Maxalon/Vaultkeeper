package com.vaultkeeper.app.data.auth

import com.vaultkeeper.app.data.api.AuthApi
import com.vaultkeeper.app.data.api.dto.LoginRequest
import com.vaultkeeper.app.data.api.dto.UserDto
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

class AuthRepository(
    private val api: AuthApi,
    private val tokens: TokenStore,
    unauthEvent: UnauthenticatedEvent,
) {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private val _session = MutableStateFlow(initialSession())
    val session: StateFlow<Session> = _session.asStateFlow()

    init {
        scope.launch {
            unauthEvent.flow.collect {
                _session.value = Session.Unauthenticated
            }
        }
    }

    suspend fun login(username: String, password: String): Result<UserDto> = runCatching {
        val response = api.login(LoginRequest(username, password))
        tokens.token = response.access_token
        _session.value = Session.Authenticated(response.user)
        response.user
    }.onFailure {
        tokens.clear()
        _session.value = Session.Unauthenticated
    }

    suspend fun completeOnboarding(): Result<Unit> = runCatching {
        val user = api.completeOnboarding()
        _session.value = Session.Authenticated(user)
    }

    suspend fun logout() {
        runCatching { api.logout() }
        tokens.clear()
        _session.value = Session.Unauthenticated
    }

    private fun initialSession(): Session =
        if (tokens.token != null) Session.Unknown else Session.Unauthenticated
}

sealed interface Session {
    data object Unauthenticated : Session
    data object Unknown : Session
    data class Authenticated(val user: UserDto) : Session
}

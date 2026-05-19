package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.Serializable

@Serializable
data class LoginRequest(
    val username: String,
    val password: String,
)

@Serializable
data class LoginResponse(
    val access_token: String,
    val token_type: String,
    val expires_in: Long,
    val user: UserDto,
)

// Returned by POST /auth/refresh — same shape as login since both go through
// respondWithToken() on the backend. Kept as a distinct type for clarity.
@Serializable
data class RefreshResponse(
    val access_token: String,
    val token_type: String,
    val expires_in: Long,
)

@Serializable
data class UserDto(
    val id: Long,
    val username: String,
    val email: String? = null,
)

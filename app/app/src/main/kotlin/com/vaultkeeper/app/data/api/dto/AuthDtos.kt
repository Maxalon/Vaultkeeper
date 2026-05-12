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

@Serializable
data class UserDto(
    val id: Long,
    val username: String,
    val email: String? = null,
)

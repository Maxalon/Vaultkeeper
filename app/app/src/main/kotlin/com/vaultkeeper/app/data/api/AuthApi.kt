package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.LoginRequest
import com.vaultkeeper.app.data.api.dto.LoginResponse
import com.vaultkeeper.app.data.api.dto.UserDto
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

interface AuthApi {

    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): LoginResponse

    @POST("auth/logout")
    suspend fun logout()

    @GET("auth/me")
    suspend fun me(): UserDto
}

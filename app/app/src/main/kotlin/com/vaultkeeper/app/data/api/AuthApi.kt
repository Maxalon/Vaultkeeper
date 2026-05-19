package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.ForgotPasswordRequest
import com.vaultkeeper.app.data.api.dto.LoginRequest
import com.vaultkeeper.app.data.api.dto.LoginResponse
import com.vaultkeeper.app.data.api.dto.RefreshResponse
import com.vaultkeeper.app.data.api.dto.RegisterRequest
import com.vaultkeeper.app.data.api.dto.ResetPasswordRequest
import com.vaultkeeper.app.data.api.dto.UserDto
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST

interface AuthApi {

    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): LoginResponse

    @POST("auth/register")
    suspend fun register(@Body body: RegisterRequest): LoginResponse

    @POST("auth/forgot-password")
    suspend fun forgotPassword(@Body body: ForgotPasswordRequest)

    @POST("auth/reset-password")
    suspend fun resetPassword(@Body body: ResetPasswordRequest)

    @POST("auth/logout")
    suspend fun logout()

    @POST("auth/refresh")
    suspend fun refresh(): RefreshResponse

    @GET("auth/me")
    suspend fun me(): UserDto
}

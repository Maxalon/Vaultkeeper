package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.RefreshResponse
import retrofit2.Call
import retrofit2.http.Header
import retrofit2.http.POST

// Synchronous Call<> — used only by TokenRefreshInterceptor, which runs on the
// OkHttp thread pool and must not suspend. Lives behind a bare OkHttpClient
// (no auth interceptors) to avoid a circular dependency with the main client.
interface AuthRefreshApi {
    @POST("auth/refresh")
    fun refresh(@Header("Authorization") bearer: String): Call<RefreshResponse>
}

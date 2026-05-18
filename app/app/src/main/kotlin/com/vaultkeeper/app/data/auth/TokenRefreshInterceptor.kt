package com.vaultkeeper.app.data.auth

import com.vaultkeeper.app.data.api.AuthRefreshApi
import okhttp3.Interceptor
import okhttp3.Response

class TokenRefreshInterceptor(
    private val tokens: TokenStore,
    private val unauthEvent: UnauthenticatedEvent,
    private val authRefreshApi: AuthRefreshApi,
) : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val originalResponse = chain.proceed(chain.request())

        if (originalResponse.code != 401) return originalResponse

        // Avoid infinite loop if this IS the refresh endpoint.
        if (chain.request().url.encodedPath.endsWith("/auth/refresh")) {
            unauthEvent.emit()
            return originalResponse
        }

        originalResponse.close()

        val currentToken = tokens.token
        if (currentToken == null) {
            unauthEvent.emit()
            return chain.proceed(chain.request())
        }

        val refreshed = tryRefresh(currentToken)
        if (!refreshed) {
            tokens.clear()
            unauthEvent.emit()
            return chain.proceed(chain.request())
        }

        // AuthInterceptor already ran and won't re-run for this chain.proceed(),
        // so we attach the new token explicitly.
        val newToken = tokens.token
        val retryRequest = chain.request().newBuilder()
            .apply { if (newToken != null) header("Authorization", "Bearer $newToken") }
            .build()
        return chain.proceed(retryRequest)
    }

    @Synchronized
    private fun tryRefresh(oldToken: String): Boolean {
        // A concurrent request may have already refreshed — if the stored token
        // changed, the refresh already happened and we can skip ahead.
        val current = tokens.token ?: return false
        if (current != oldToken) return true

        return try {
            val response = authRefreshApi.refresh("Bearer $current").execute()
            val body = response.body()
            if (response.isSuccessful && body != null) {
                tokens.token = body.access_token
                true
            } else {
                false
            }
        } catch (_: Exception) {
            false
        }
    }
}

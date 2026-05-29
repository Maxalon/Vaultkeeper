package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.PrivacySettingsPatch
import com.vaultkeeper.app.data.api.dto.PrivacySettingsResponse
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PATCH

interface PrivacyApi {
    @GET("privacy-settings")
    suspend fun get(): PrivacySettingsResponse

    @PATCH("privacy-settings")
    suspend fun patch(@Body body: PrivacySettingsPatch): PrivacySettingsResponse
}

package com.vaultkeeper.app.data.privacy

import com.vaultkeeper.app.data.api.PrivacyApi
import com.vaultkeeper.app.data.api.dto.PrivacySettingsDto
import com.vaultkeeper.app.data.api.dto.PrivacySettingsPatch

class PrivacyRepository(private val api: PrivacyApi) {
    suspend fun get(): Result<PrivacySettingsDto> = runCatching { api.get().data }
    suspend fun patch(patch: PrivacySettingsPatch): Result<PrivacySettingsDto> =
        runCatching { api.patch(patch).data }
}

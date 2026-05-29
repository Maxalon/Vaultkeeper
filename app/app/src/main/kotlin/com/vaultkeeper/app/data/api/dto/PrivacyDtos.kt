package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.Serializable

@Serializable
data class PrivacySettingsDto(
    val collection_visibility: String,
    val decks_visibility: String,
    val discoverable: Boolean,
)

@Serializable
data class PrivacySettingsResponse(val data: PrivacySettingsDto)

@Serializable
data class PrivacySettingsPatch(
    val collection_visibility: String? = null,
    val decks_visibility: String? = null,
    val discoverable: Boolean? = null,
)

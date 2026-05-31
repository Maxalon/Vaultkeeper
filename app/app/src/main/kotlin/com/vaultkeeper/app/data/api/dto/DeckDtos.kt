package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.Serializable

@Serializable
data class DeckEntryDto(
    val id: Long,
    val scryfall_id: String,
    val zone: String,
    val quantity: Int,
    val category: String? = null,
    val is_commander: Boolean = false,
    val wanted: String? = null,
)

@Serializable
data class MoveZoneRequest(
    val zone: String,
)

package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class DeckDto(
    val id: Long,
    val name: String,
    val format: String,
    val description: String? = null,
    @SerialName("is_assembled") val isAssembled: Boolean = false,
    @SerialName("is_archived") val isArchived: Boolean = false,
    @SerialName("entry_count") val entryCount: Int = 0,
    val commander1: CommanderDto? = null,
    val commander2: CommanderDto? = null,
)

@Serializable
data class CommanderDto(
    @SerialName("scryfall_id") val scryfallId: String,
    val name: String,
)

@Serializable
data class CreateDeckRequest(
    val name: String,
    val format: String,
)

package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class DeckSummaryDto(
    val id: Long,
    val name: String,
    val format: String,
    @SerialName("entry_count") val entryCount: Int = 0,
    @SerialName("color_identity") val colorIdentity: String? = null,
    @SerialName("is_archived") val isArchived: Boolean = false,
    val commander1: CommanderDto? = null,
    val commander2: CommanderDto? = null,
)

@Serializable
data class DeckDetailDto(
    val id: Long,
    val name: String,
    val format: String,
    @SerialName("is_archived") val isArchived: Boolean = false,
    val commander1: CommanderDto? = null,
    val commander2: CommanderDto? = null,
)

@Serializable
data class CommanderDto(
    @SerialName("scryfall_id") val scryfallId: String,
    val name: String,
    @SerialName("image_small") val imageSmall: String? = null,
)

@Serializable
data class DeckEntryDto(
    val id: Long,
    @SerialName("scryfall_id") val scryfallId: String,
    val quantity: Int,
    val zone: String,
    val category: String? = null,
    @SerialName("is_commander") val isCommander: Boolean = false,
    @SerialName("is_signature_spell") val isSignatureSpell: Boolean = false,
    val wanted: String? = null,
    @SerialName("physical_copy_id") val physicalCopyId: Long? = null,
    val card: CardDto? = null,
)

@Serializable
data class CardDto(
    @SerialName("scryfall_id") val scryfallId: String,
    val name: String,
    @SerialName("type_line") val typeLine: String? = null,
    @SerialName("mana_cost") val manaCost: String? = null,
    @SerialName("image_small") val imageSmall: String? = null,
)

@Serializable
data class ScryfallCardDto(
    @SerialName("scryfall_id") val scryfallId: String,
    val name: String,
    @SerialName("type_line") val typeLine: String? = null,
    @SerialName("mana_cost") val manaCost: String? = null,
    @SerialName("image_small") val imageSmall: String? = null,
)

@Serializable
data class ScryfallSearchResponse(
    @SerialName("current_page") val currentPage: Int = 1,
    val data: List<ScryfallCardDto> = emptyList(),
    @SerialName("last_page") val lastPage: Int = 1,
    @SerialName("per_page") val perPage: Int = 60,
    val total: Int = 0,
    val warnings: List<String> = emptyList(),
)

@Serializable
data class AddEntryRequest(
    @SerialName("scryfall_id") val scryfallId: String,
    val zone: String = "main",
    val quantity: Int = 1,
)

@Serializable
data class PatchEntryRequest(
    val zone: String? = null,
    val quantity: Int? = null,
)

@Serializable
data class UpdateDeckRequest(
    @SerialName("commander_1_scryfall_id") val commander1ScryfallId: String? = null,
)

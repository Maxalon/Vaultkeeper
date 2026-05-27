package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class DeckEntryDto(
    val id: Int,
    @SerialName("deck_id") val deckId: Int,
    @SerialName("scryfall_id") val scryfallId: String,
    val quantity: Int,
    val zone: String,
    @SerialName("is_commander") val isCommander: Boolean,
    @SerialName("scryfall_card") val scryfallCard: ScryfallCardDto?,
)

@Serializable
data class ScryfallCardDto(
    @SerialName("scryfall_id") val scryfallId: String,
    val name: String,
    @SerialName("mana_cost") val manaCost: String? = null,
    @SerialName("type_line") val typeLine: String? = null,
    @SerialName("image_normal") val imageNormal: String? = null,
)

@Serializable
data class UpdateEntryRequest(
    val zone: String,
)

package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.Serializable

@Serializable
data class DeckDetailResponse(
    val id: Long,
    val name: String,
    val format: String,
    val description: String? = null,
    val color_identity: List<String> = emptyList(),
    val is_archived: Boolean = false,
    val commander1: CommanderCardDto? = null,
    val commander2: CommanderCardDto? = null,
    val companion: CompanionCardDto? = null,
    val companion_scryfall_id: String? = null,
)

@Serializable
data class CommanderCardDto(
    val scryfall_id: String,
    val name: String,
    val image_small: String? = null,
    val image_normal: String? = null,
    val color_identity: List<String> = emptyList(),
    val commander_game_changer: Boolean = false,
)

@Serializable
data class CompanionCardDto(
    val scryfall_id: String,
    val name: String,
    val image_small: String? = null,
    val image_normal: String? = null,
    val color_identity: List<String> = emptyList(),
    val keywords: List<String> = emptyList(),
)

@Serializable
data class DeckEntryDto(
    val id: Long,
    val deck_id: Long,
    val scryfall_id: String,
    val quantity: Int,
    val zone: String,
    val category: String? = null,
    val is_commander: Boolean = false,
    val is_signature_spell: Boolean = false,
    val signature_for_entry_id: Long? = null,
    val wanted: String? = null,
    val physical_copy_id: Long? = null,
    val foil: Boolean = false,
    val is_etched: Boolean = false,
    val scryfall_card: ScryfallCardDto? = null,
    val owned_copies: Int = 0,
    val available_copies: Int = 0,
)

@Serializable
data class ScryfallCardDto(
    val scryfall_id: String,
    val oracle_id: String? = null,
    val name: String,
    val set_code: String? = null,
    val collector_number: String? = null,
    val rarity: String? = null,
    val type_line: String? = null,
    val mana_cost: String? = null,
    val cmc: Double = 0.0,
    val colors: List<String> = emptyList(),
    val color_identity: List<String> = emptyList(),
    val oracle_text: String? = null,
    val is_dfc: Boolean = false,
    val image_small: String? = null,
    val image_normal: String? = null,
    val image_small_back: String? = null,
    val image_normal_back: String? = null,
    val finishes: List<String> = emptyList(),
    val keywords: List<String> = emptyList(),
)

@Serializable
data class IllegalityDto(
    val type: String,
    val scryfall_id_1: String? = null,
    val scryfall_id_2: String? = null,
    val oracle_id: String? = null,
    val expected_count: Int? = null,
    val message: String = "",
    val card_name: String? = null,
    val ignored: Boolean = false,
)

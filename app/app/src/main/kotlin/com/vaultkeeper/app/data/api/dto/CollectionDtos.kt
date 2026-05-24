package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class CollectionResponseDto(
    val data: List<CollectionEntryDto>,
    val warnings: List<String> = emptyList(),
    val meta: PaginationMetaDto? = null,
)

@Serializable
data class PaginationMetaDto(
    @SerialName("current_page") val currentPage: Int,
    @SerialName("per_page") val perPage: Int,
    val total: Int,
    @SerialName("has_more") val hasMore: Boolean,
)

@Serializable
data class CollectionEntryDto(
    val id: Int,
    val quantity: Int,
    val condition: String,
    val foil: Boolean,
    @SerialName("is_etched") val isEtched: Boolean,
    val notes: String? = null,
    @SerialName("location_id") val locationId: Int? = null,
    val version: Int = 0,
    val card: CardDto? = null,
)

@Serializable
data class CardDto(
    @SerialName("scryfall_id") val scryfallId: String,
    val name: String,
    @SerialName("set_code") val setCode: String,
    @SerialName("collector_number") val collectorNumber: String,
    val rarity: String? = null,
    @SerialName("image_small") val imageSmall: String? = null,
    val prices: PricesDto? = null,
)

@Serializable
data class PricesDto(
    val eur: String? = null,
    @SerialName("eur_foil") val eurFoil: String? = null,
    @SerialName("eur_etched") val eurEtched: String? = null,
)

@Serializable
data class LocationsResponseDto(
    val locations: List<LocationDto>,
    @SerialName("total_count") val totalCount: Int,
)

@Serializable
data class LocationDto(
    val id: Int,
    val type: String,
    val name: String,
    @SerialName("card_count") val cardCount: Int,
)

@Serializable
data class CollectionTotalsDto(
    val total: Double,
    @SerialName("card_count") val cardCount: Int,
    @SerialName("missing_price_count") val missingPriceCount: Int,
)

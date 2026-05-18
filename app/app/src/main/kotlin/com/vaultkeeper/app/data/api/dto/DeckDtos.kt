package com.vaultkeeper.app.data.api.dto

import kotlinx.serialization.Serializable

@Serializable
data class DeckDto(
    val id: Long,
    val name: String,
    val format: String,
)

@Serializable
data class DeckImportResult(
    val deck: DeckDto,
    val imported: Int,
    val skipped: Int,
    val warnings: List<String> = emptyList(),
    val action: String = "created",
)

@Serializable
data class TextImportRequest(
    val source: String = "text",
    val text: String,
    val name: String,
    val format: String,
)

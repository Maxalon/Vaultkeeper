package com.vaultkeeper.app.data.repository

import com.vaultkeeper.app.data.api.DeckApi
import com.vaultkeeper.app.data.api.dto.CreateDeckRequest
import com.vaultkeeper.app.data.api.dto.DeckDto

class DeckRepository(private val api: DeckApi) {

    suspend fun listDecks(): Result<List<DeckDto>> = runCatching { api.listDecks() }

    suspend fun createDeck(name: String, format: String): Result<DeckDto> =
        runCatching { api.createDeck(CreateDeckRequest(name, format)) }

    suspend fun deleteDeck(id: Long): Result<Unit> = runCatching { api.deleteDeck(id) }
}

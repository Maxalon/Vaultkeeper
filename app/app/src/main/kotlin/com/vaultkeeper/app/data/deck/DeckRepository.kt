package com.vaultkeeper.app.data.deck

import com.vaultkeeper.app.data.api.DeckApi
import com.vaultkeeper.app.data.api.dto.AddEntryRequest
import com.vaultkeeper.app.data.api.dto.DeckDetailDto
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.DeckSummaryDto
import com.vaultkeeper.app.data.api.dto.PatchEntryRequest
import com.vaultkeeper.app.data.api.dto.ScryfallCardDto
import com.vaultkeeper.app.data.api.dto.UpdateDeckRequest

class DeckRepository(private val api: DeckApi) {

    suspend fun listDecks(): Result<List<DeckSummaryDto>> = runCatching {
        api.listDecks()
    }

    suspend fun getDeck(deckId: Long): Result<DeckDetailDto> = runCatching {
        api.getDeck(deckId)
    }

    suspend fun listEntries(deckId: Long): Result<List<DeckEntryDto>> = runCatching {
        api.listEntries(deckId)
    }

    suspend fun addEntry(
        deckId: Long,
        scryfallId: String,
        zone: String = "main",
    ): Result<DeckEntryDto> = runCatching {
        api.addEntry(deckId, AddEntryRequest(scryfallId = scryfallId, zone = zone))
    }

    suspend fun updateEntryQuantity(
        deckId: Long,
        entryId: Long,
        quantity: Int,
    ): Result<DeckEntryDto> = runCatching {
        api.updateEntry(deckId, entryId, PatchEntryRequest(quantity = quantity))
    }

    suspend fun updateEntryZone(
        deckId: Long,
        entryId: Long,
        zone: String,
    ): Result<DeckEntryDto> = runCatching {
        api.updateEntry(deckId, entryId, PatchEntryRequest(zone = zone))
    }

    suspend fun deleteEntry(deckId: Long, entryId: Long): Result<Unit> = runCatching {
        api.deleteEntry(deckId, entryId)
    }

    suspend fun setCommander(deckId: Long, scryfallId: String): Result<DeckDetailDto> = runCatching {
        api.updateDeck(deckId, UpdateDeckRequest(commander1ScryfallId = scryfallId))
    }

    suspend fun searchCards(query: String): Result<List<ScryfallCardDto>> = runCatching {
        api.searchCards(query).data
    }
}

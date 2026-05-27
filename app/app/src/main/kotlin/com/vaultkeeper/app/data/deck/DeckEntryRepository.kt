package com.vaultkeeper.app.data.deck

import com.vaultkeeper.app.data.api.DeckEntryApi
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.UpdateEntryRequest

class DeckEntryRepository(private val api: DeckEntryApi) {

    suspend fun getEntries(deckId: Int): List<DeckEntryDto> =
        api.getEntries(deckId)

    suspend fun moveToZone(deckId: Int, entryId: Int, zone: String): DeckEntryDto =
        api.updateEntry(deckId, entryId, UpdateEntryRequest(zone))
}

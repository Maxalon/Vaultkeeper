package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.MoveZoneRequest
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.Path

interface DeckApi {

    @GET("decks/{deckId}/entries")
    suspend fun getEntries(@Path("deckId") deckId: Long): List<DeckEntryDto>

    @PATCH("decks/{deckId}/entries/{entryId}")
    suspend fun updateEntry(
        @Path("deckId") deckId: Long,
        @Path("entryId") entryId: Long,
        @Body body: MoveZoneRequest,
    ): DeckEntryDto
}

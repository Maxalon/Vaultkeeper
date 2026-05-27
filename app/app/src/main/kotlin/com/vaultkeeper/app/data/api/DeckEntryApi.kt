package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.UpdateEntryRequest
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.Path

interface DeckEntryApi {

    @GET("decks/{deckId}/entries")
    suspend fun getEntries(@Path("deckId") deckId: Int): List<DeckEntryDto>

    @PATCH("decks/{deckId}/entries/{entryId}")
    suspend fun updateEntry(
        @Path("deckId") deckId: Int,
        @Path("entryId") entryId: Int,
        @Body body: UpdateEntryRequest,
    ): DeckEntryDto
}

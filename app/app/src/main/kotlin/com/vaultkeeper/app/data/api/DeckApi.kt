package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.AddEntryRequest
import com.vaultkeeper.app.data.api.dto.DeckDetailDto
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.DeckSummaryDto
import com.vaultkeeper.app.data.api.dto.PatchEntryRequest
import com.vaultkeeper.app.data.api.dto.ScryfallSearchResponse
import com.vaultkeeper.app.data.api.dto.UpdateDeckRequest
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.PATCH
import retrofit2.http.POST
import retrofit2.http.PUT
import retrofit2.http.Path
import retrofit2.http.Query

interface DeckApi {

    @GET("decks")
    suspend fun listDecks(): List<DeckSummaryDto>

    @GET("decks/{deckId}")
    suspend fun getDeck(@Path("deckId") deckId: Long): DeckDetailDto

    @GET("decks/{deckId}/entries")
    suspend fun listEntries(@Path("deckId") deckId: Long): List<DeckEntryDto>

    @POST("decks/{deckId}/entries")
    suspend fun addEntry(
        @Path("deckId") deckId: Long,
        @Body request: AddEntryRequest,
    ): DeckEntryDto

    @PATCH("decks/{deckId}/entries/{entryId}")
    suspend fun updateEntry(
        @Path("deckId") deckId: Long,
        @Path("entryId") entryId: Long,
        @Body request: PatchEntryRequest,
    ): DeckEntryDto

    @DELETE("decks/{deckId}/entries/{entryId}")
    suspend fun deleteEntry(
        @Path("deckId") deckId: Long,
        @Path("entryId") entryId: Long,
    )

    @PUT("decks/{deckId}")
    suspend fun updateDeck(
        @Path("deckId") deckId: Long,
        @Body request: UpdateDeckRequest,
    ): DeckDetailDto

    @GET("scryfall-cards/search")
    suspend fun searchCards(
        @Query("q") query: String,
        @Query("per_page") perPage: Int = 30,
    ): ScryfallSearchResponse
}

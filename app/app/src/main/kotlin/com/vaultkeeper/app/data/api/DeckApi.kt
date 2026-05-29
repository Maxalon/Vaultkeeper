package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.CreateDeckRequest
import com.vaultkeeper.app.data.api.dto.DeckDto
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

interface DeckApi {

    @GET("decks")
    suspend fun listDecks(): List<DeckDto>

    @POST("decks")
    suspend fun createDeck(@Body body: CreateDeckRequest): DeckDto

    @DELETE("decks/{id}")
    suspend fun deleteDeck(@Path("id") id: Long)
}

package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.DeckDetailResponse
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.IllegalityDto
import retrofit2.http.GET
import retrofit2.http.Path

interface DeckApi {

    @GET("decks/{id}")
    suspend fun getDeck(@Path("id") id: Long): DeckDetailResponse

    @GET("decks/{id}/entries")
    suspend fun getEntries(@Path("id") id: Long): List<DeckEntryDto>

    @GET("decks/{id}/illegalities")
    suspend fun getIllegalities(@Path("id") id: Long): List<IllegalityDto>
}

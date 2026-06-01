package com.vaultkeeper.app.data.repository

import com.vaultkeeper.app.data.api.DeckApi
import com.vaultkeeper.app.data.api.dto.DeckDetailResponse
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.IllegalityDto

class DeckRepository(private val api: DeckApi) {

    suspend fun getDeck(id: Long): DeckDetailResponse = api.getDeck(id)

    suspend fun getEntries(id: Long): List<DeckEntryDto> = api.getEntries(id)

    suspend fun getIllegalities(id: Long): List<IllegalityDto> = api.getIllegalities(id)
}

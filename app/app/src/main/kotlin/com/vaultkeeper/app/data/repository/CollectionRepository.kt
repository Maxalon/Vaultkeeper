package com.vaultkeeper.app.data.repository

import com.vaultkeeper.app.data.api.CollectionApi
import com.vaultkeeper.app.data.api.dto.CollectionResponseDto
import com.vaultkeeper.app.data.api.dto.CollectionTotalsDto
import com.vaultkeeper.app.data.api.dto.LocationsResponseDto

class CollectionRepository(private val api: CollectionApi) {

    suspend fun getEntries(locationId: Int? = null, page: Int = 1): CollectionResponseDto =
        api.getEntries(locationId = locationId, page = page)

    suspend fun getLocations(): LocationsResponseDto =
        api.getLocations()

    suspend fun getTotals(locationId: Int? = null): CollectionTotalsDto =
        api.getTotals(locationId = locationId)
}

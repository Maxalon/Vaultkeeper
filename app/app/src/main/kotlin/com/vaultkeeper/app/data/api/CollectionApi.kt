package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.CollectionResponseDto
import com.vaultkeeper.app.data.api.dto.CollectionTotalsDto
import com.vaultkeeper.app.data.api.dto.LocationsResponseDto
import retrofit2.http.GET
import retrofit2.http.Query

interface CollectionApi {

    @GET("collection")
    suspend fun getEntries(
        @Query("location_id") locationId: Int? = null,
        @Query("q") query: String? = null,
        @Query("page") page: Int = 1,
    ): CollectionResponseDto

    @GET("locations")
    suspend fun getLocations(): LocationsResponseDto

    @GET("collection/totals")
    suspend fun getTotals(
        @Query("location_id") locationId: Int? = null,
    ): CollectionTotalsDto
}

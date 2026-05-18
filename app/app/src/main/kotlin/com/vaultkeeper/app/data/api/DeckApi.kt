package com.vaultkeeper.app.data.api

import com.vaultkeeper.app.data.api.dto.DeckImportResult
import com.vaultkeeper.app.data.api.dto.TextImportRequest
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Body
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part

interface DeckApi {

    @POST("decks/import")
    suspend fun importText(@Body body: TextImportRequest): DeckImportResult

    @Multipart
    @POST("decks/import/csv")
    suspend fun importCsv(
        @Part csvFile: MultipartBody.Part,
        @Part("name") name: RequestBody,
        @Part("format") format: RequestBody,
    ): DeckImportResult
}

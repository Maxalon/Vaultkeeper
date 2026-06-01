package com.vaultkeeper.app.data.deck

import android.app.Application
import android.net.Uri
import android.provider.OpenableColumns
import com.vaultkeeper.app.data.api.DeckApi
import com.vaultkeeper.app.data.api.dto.DeckImportResult
import com.vaultkeeper.app.data.api.dto.TextImportRequest
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException

class DeckRepository(
    private val api: DeckApi,
    private val app: Application,
) {

    suspend fun importFromText(text: String, name: String, format: String): DeckImportResult =
        api.importText(TextImportRequest(text = text, name = name, format = format))

    suspend fun importFromCsv(uri: Uri, name: String, format: String): DeckImportResult {
        val cr = app.contentResolver
        val displayName = cr.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)
            ?.use { cursor -> if (cursor.moveToFirst()) cursor.getString(0) else "deck.csv" }
            ?: "deck.csv"

        val bytes = cr.openInputStream(uri)?.use { it.readBytes() }
            ?: throw IOException("Could not read selected file")

        val filePart = MultipartBody.Part.createFormData(
            "csv_file",
            displayName,
            bytes.toRequestBody("text/csv".toMediaType()),
        )
        val namePart = name.toRequestBody("text/plain".toMediaType())
        val formatPart = format.toRequestBody("text/plain".toMediaType())

        return api.importCsv(filePart, namePart, formatPart)
    }
}

package com.vaultkeeper.app.ui.decks

import android.net.Uri
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.vaultkeeper.app.data.deck.DeckRepository
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import org.json.JSONObject
import retrofit2.HttpException

enum class ImportTab { Paste, Csv }

sealed interface ImportPhase {
    data object Idle : ImportPhase
    data object Submitting : ImportPhase
    data class Error(val message: String) : ImportPhase
    data class Success(val deckId: Long, val deckName: String, val warnings: List<String>) : ImportPhase
}

data class ImportDeckUiState(
    val tab: ImportTab = ImportTab.Paste,
    val text: String = "",
    val name: String = "",
    val format: String = "commander",
    val csvUri: Uri? = null,
    val csvDisplayName: String = "",
    val csvName: String = "",
    val csvFormat: String = "commander",
    val phase: ImportPhase = ImportPhase.Idle,
)

val DECK_FORMATS = listOf("commander", "oathbreaker", "pauper", "standard", "modern")

class ImportDeckViewModel(private val repo: DeckRepository) : ViewModel() {

    private val _state = MutableStateFlow(ImportDeckUiState())
    val state: StateFlow<ImportDeckUiState> = _state.asStateFlow()

    private val _navigateToDeck = MutableSharedFlow<Long>()
    val navigateToDeck: SharedFlow<Long> = _navigateToDeck.asSharedFlow()

    fun onTabChange(tab: ImportTab) = _state.update { it.copy(tab = tab, phase = ImportPhase.Idle) }
    fun onTextChange(v: String) = _state.update { it.copy(text = v) }
    fun onNameChange(v: String) = _state.update { it.copy(name = v) }
    fun onFormatChange(v: String) = _state.update { it.copy(format = v) }
    fun onCsvFormatChange(v: String) = _state.update { it.copy(csvFormat = v) }
    fun onCsvNameChange(v: String) = _state.update { it.copy(csvName = v) }

    fun onCsvPicked(uri: Uri, displayName: String) = _state.update {
        it.copy(
            csvUri = uri,
            csvDisplayName = displayName,
            csvName = if (it.csvName.isBlank()) displayName.substringBeforeLast('.').take(100) else it.csvName,
        )
    }

    fun canSubmit(): Boolean {
        val s = _state.value
        if (s.phase is ImportPhase.Submitting) return false
        return when (s.tab) {
            ImportTab.Paste -> s.text.isNotBlank() && s.name.isNotBlank()
            ImportTab.Csv -> s.csvUri != null && s.csvName.isNotBlank()
        }
    }

    fun submit() {
        if (!canSubmit()) return
        val s = _state.value
        _state.update { it.copy(phase = ImportPhase.Submitting) }
        viewModelScope.launch {
            runCatching {
                when (s.tab) {
                    ImportTab.Paste -> repo.importFromText(s.text.trim(), s.name.trim(), s.format)
                    ImportTab.Csv -> repo.importFromCsv(s.csvUri!!, s.csvName.trim(), s.csvFormat)
                }
            }.onSuccess { result ->
                _state.update {
                    it.copy(phase = ImportPhase.Success(result.deck.id, result.deck.name, result.warnings))
                }
                _navigateToDeck.emit(result.deck.id)
            }.onFailure { e ->
                _state.update { it.copy(phase = ImportPhase.Error(errorMessage(e))) }
            }
        }
    }

    private fun errorMessage(e: Throwable): String {
        if (e is HttpException) {
            val body = runCatching { e.response()?.errorBody()?.string() }.getOrNull()
            if (!body.isNullOrBlank()) {
                return runCatching {
                    val json = JSONObject(body)
                    val msg = json.optString("message")
                    if (msg.isNotBlank()) msg else "Import failed"
                }.getOrDefault("Import failed")
            }
        }
        return e.message?.takeIf { it.isNotBlank() } ?: "Import failed"
    }
}

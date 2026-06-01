package com.vaultkeeper.app.game

import androidx.lifecycle.ViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

class GameViewModel : ViewModel() {

    private val _state = MutableStateFlow(GameState())
    val state: StateFlow<GameState> = _state.asStateFlow()

    private val undoStack = ArrayDeque<GameState>()

    fun startSession(players: List<GamePlayer>) {
        _state.value = GameState(players = players)
        undoStack.clear()
    }

    fun toggleCommanderDamageRow(playerId: String) {
        _state.update { s ->
            val next = if (playerId in s.expandedTiles) s.expandedTiles - playerId
                       else s.expandedTiles + playerId
            s.copy(expandedTiles = next)
        }
    }

    fun incrementCommanderDamage(receivingId: String, sourceId: String) {
        pushUndo()
        _state.update { s ->
            val newValue = s.commanderDamageFrom(receivingId, sourceId) + 1
            val entry = HistoryEntry(
                "${s.playerName(sourceId)} dealt $newValue cmdr dmg to ${s.playerName(receivingId)}"
            )
            s.withDamage(receivingId, sourceId, newValue).copy(history = s.history + entry)
        }
    }

    fun decrementCommanderDamage(receivingId: String, sourceId: String) {
        val current = _state.value.commanderDamageFrom(receivingId, sourceId)
        if (current <= 0) return
        pushUndo()
        _state.update { s ->
            val newValue = current - 1
            val entry = HistoryEntry(
                "${s.playerName(sourceId)} cmdr dmg to ${s.playerName(receivingId)} corrected to $newValue"
            )
            s.withDamage(receivingId, sourceId, newValue).copy(history = s.history + entry)
        }
    }

    fun undo() {
        undoStack.removeLastOrNull()?.let { _state.value = it }
    }

    private fun pushUndo() {
        undoStack.addLast(_state.value)
    }

    private fun GameState.playerName(id: String): String =
        players.firstOrNull { it.id == id }?.name ?: id

    private fun GameState.withDamage(
        receivingId: String,
        sourceId: String,
        value: Int,
    ): GameState {
        val receiverMap = (commanderDamage[receivingId] ?: emptyMap()) + (sourceId to value)
        return copy(commanderDamage = commanderDamage + (receivingId to receiverMap))
    }
}

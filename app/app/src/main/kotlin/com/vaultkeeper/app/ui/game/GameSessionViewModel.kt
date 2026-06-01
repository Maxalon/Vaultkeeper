package com.vaultkeeper.app.ui.game

import androidx.lifecycle.ViewModel
import com.vaultkeeper.app.game.GamePlayer
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update

class GameSessionViewModel : ViewModel() {

    private val _session = MutableStateFlow(defaultSession())
    val session: StateFlow<GameSession> = _session.asStateFlow()

    fun reset(players: List<GamePlayer>) {
        _session.value = GameSession(players = players)
    }

    fun applyKeypadAdjustment(playerId: String, delta: Int) {
        _session.update { session ->
            val player = session.players.firstOrNull { it.id == playerId } ?: return@update session
            val adjustment = LifeAdjustment(
                playerId = playerId,
                delta = delta,
                previousLife = player.lifeTotal,
            )
            session.copy(
                players = session.players.map {
                    if (it.id == playerId) it.copy(lifeTotal = it.lifeTotal + delta) else it
                },
                history = session.history + adjustment,
            )
        }
    }

    fun undoLast() {
        _session.update { session ->
            val last = session.history.lastOrNull() ?: return@update session
            session.copy(
                players = session.players.map {
                    if (it.id == last.playerId) it.copy(lifeTotal = last.previousLife) else it
                },
                history = session.history.dropLast(1),
            )
        }
    }

    companion object {
        private fun defaultSession() = GameSession(
            players = listOf(
                GamePlayer(id = "0", name = "Player 1", lifeTotal = 40),
                GamePlayer(id = "1", name = "Player 2", lifeTotal = 40),
            ),
        )
    }
}

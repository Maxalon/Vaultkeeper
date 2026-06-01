package com.vaultkeeper.app.ui.game

import com.vaultkeeper.app.game.GamePlayer

data class LifeAdjustment(
    val playerId: String,
    val delta: Int,
    val previousLife: Int,
)

data class GameSession(
    val players: List<GamePlayer>,
    val history: List<LifeAdjustment> = emptyList(),
)

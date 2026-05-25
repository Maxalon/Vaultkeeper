package com.vaultkeeper.app.ui.game

data class Player(
    val id: Int,
    val name: String,
    val lifeTotal: Int,
)

data class LifeAdjustment(
    val playerId: Int,
    val delta: Int,
    val previousLife: Int,
)

data class GameSession(
    val players: List<Player>,
    val history: List<LifeAdjustment> = emptyList(),
)

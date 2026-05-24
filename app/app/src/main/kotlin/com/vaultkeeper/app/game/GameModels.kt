package com.vaultkeeper.app.game

data class GamePlayer(
    val id: String,
    val name: String,
    val lifeTotal: Int = 40,
)

data class HistoryEntry(val description: String)

data class GameState(
    val players: List<GamePlayer> = emptyList(),
    // commanderDamage[receivingPlayerId][sourcePlayerId] = amount
    val commanderDamage: Map<String, Map<String, Int>> = emptyMap(),
    val expandedTiles: Set<String> = emptySet(),
    val history: List<HistoryEntry> = emptyList(),
)

fun GameState.commanderDamageReceived(playerId: String): Int =
    commanderDamage[playerId]?.values?.sum() ?: 0

fun GameState.commanderDamageFrom(receivingId: String, sourceId: String): Int =
    commanderDamage[receivingId]?.get(sourceId) ?: 0

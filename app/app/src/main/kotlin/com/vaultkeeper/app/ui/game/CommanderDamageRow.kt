package com.vaultkeeper.app.ui.game

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.vaultkeeper.app.game.GamePlayer

internal const val COMMANDER_DAMAGE_KO = 21

@Composable
fun CommanderDamageRow(
    opponents: List<GamePlayer>,
    commanderDamageMap: Map<String, Int>,
    onIncrement: (sourceId: String) -> Unit,
    onDecrement: (sourceId: String) -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        opponents.forEach { opponent ->
            CommanderDamageChip(
                opponentName = opponent.name,
                damage = commanderDamageMap[opponent.id] ?: 0,
                onTap = { onIncrement(opponent.id) },
                onLongPress = { onDecrement(opponent.id) },
            )
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun CommanderDamageChip(
    opponentName: String,
    damage: Int,
    onTap: () -> Unit,
    onLongPress: () -> Unit,
) {
    val isKo = damage >= COMMANDER_DAMAGE_KO
    val containerColor = if (isKo) MaterialTheme.colorScheme.error
                         else MaterialTheme.colorScheme.surfaceVariant
    val contentColor = if (isKo) MaterialTheme.colorScheme.onError
                       else MaterialTheme.colorScheme.onSurfaceVariant

    Surface(
        shape = RoundedCornerShape(50),
        color = containerColor,
        modifier = Modifier.combinedClickable(onClick = onTap, onLongClick = onLongPress),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Text(
                text = opponentName,
                style = MaterialTheme.typography.labelMedium,
                color = contentColor,
            )
            Text(
                text = damage.toString(),
                style = MaterialTheme.typography.labelLarge,
                color = contentColor,
            )
        }
    }
}

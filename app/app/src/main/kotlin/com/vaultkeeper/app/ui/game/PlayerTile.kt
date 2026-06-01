package com.vaultkeeper.app.ui.game

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Dialpad
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material3.Card
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.vaultkeeper.app.game.GamePlayer
import com.vaultkeeper.app.game.GameState
import com.vaultkeeper.app.game.commanderDamageReceived

@Composable
fun PlayerTile(
    player: GamePlayer,
    commanderState: GameState,
    onDeltaConfirmed: (delta: Int) -> Unit,
    onToggleCommanderDamage: () -> Unit,
    onIncrementCommanderDamage: (sourceId: String) -> Unit,
    onDecrementCommanderDamage: (sourceId: String) -> Unit,
    modifier: Modifier = Modifier,
) {
    var showKeypad by remember { mutableStateOf(false) }

    val opponents = commanderState.players.filter { it.id != player.id }
    val totalCmdrDamage = commanderState.commanderDamageReceived(player.id)
    val isExpanded = player.id in commanderState.expandedTiles

    Card(
        modifier = modifier,
        shape = RoundedCornerShape(12.dp),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(12.dp),
        ) {
            Column(modifier = Modifier.fillMaxWidth()) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        text = player.name,
                        style = MaterialTheme.typography.titleMedium,
                    )
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        CommanderDamageSummaryBadge(total = totalCmdrDamage)
                        Spacer(Modifier.width(4.dp))
                        IconButton(onClick = onToggleCommanderDamage) {
                            Icon(
                                imageVector = Icons.Default.Shield,
                                contentDescription = if (isExpanded) "Collapse commander damage"
                                                     else "Show commander damage",
                                tint = if (isExpanded) MaterialTheme.colorScheme.primary
                                       else MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }

                Text(
                    text = player.lifeTotal.toString(),
                    fontSize = 72.sp,
                    lineHeight = 76.sp,
                    modifier = Modifier.align(Alignment.CenterHorizontally),
                )

                AnimatedVisibility(visible = isExpanded) {
                    CommanderDamageRow(
                        opponents = opponents,
                        commanderDamageMap = commanderState.commanderDamage[player.id] ?: emptyMap(),
                        onIncrement = onIncrementCommanderDamage,
                        onDecrement = onDecrementCommanderDamage,
                        modifier = Modifier.padding(top = 8.dp),
                    )
                }
            }

            IconButton(
                onClick = { showKeypad = true },
                modifier = Modifier.align(Alignment.BottomEnd),
            ) {
                Icon(
                    imageVector = Icons.Default.Dialpad,
                    contentDescription = "Enter life adjustment for ${player.name}",
                )
            }
        }
    }

    if (showKeypad) {
        LifeKeypadOverlay(
            currentLife = player.lifeTotal,
            onConfirm = { delta ->
                onDeltaConfirmed(delta)
                showKeypad = false
            },
            onDismiss = { showKeypad = false },
        )
    }
}

@Composable
private fun CommanderDamageSummaryBadge(total: Int) {
    val isKo = total >= COMMANDER_DAMAGE_KO
    Box(
        modifier = Modifier
            .background(
                color = if (isKo) MaterialTheme.colorScheme.error
                        else MaterialTheme.colorScheme.secondaryContainer,
                shape = CircleShape,
            )
            .padding(horizontal = 8.dp, vertical = 2.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            text = total.toString(),
            style = MaterialTheme.typography.labelMedium,
            color = if (isKo) MaterialTheme.colorScheme.onError
                    else MaterialTheme.colorScheme.onSecondaryContainer,
        )
    }
}

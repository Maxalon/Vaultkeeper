package com.vaultkeeper.app.ui.game

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Dialpad
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
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

@Composable
fun PlayerTile(
    player: Player,
    onDeltaConfirmed: (delta: Int) -> Unit,
    modifier: Modifier = Modifier,
) {
    var showKeypad by remember { mutableStateOf(false) }

    Surface(
        modifier = modifier,
        color = MaterialTheme.colorScheme.surfaceVariant,
        shape = MaterialTheme.shapes.medium,
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(8.dp),
        ) {
            Column(
                modifier = Modifier.align(Alignment.Center),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Text(
                    text = player.name,
                    style = MaterialTheme.typography.titleMedium,
                )
                Text(
                    text = player.lifeTotal.toString(),
                    fontSize = 72.sp,
                    lineHeight = 76.sp,
                )
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

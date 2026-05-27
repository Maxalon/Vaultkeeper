package com.vaultkeeper.app.ui.deck

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.SwapHoriz
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SuggestionChip
import androidx.compose.material3.SuggestionChipDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import kotlinx.coroutines.launch
import org.koin.androidx.compose.koinViewModel
import kotlin.math.abs
import kotlin.math.roundToInt

private val ZONES = listOf("main", "side", "maybe")
private val REVEAL_WIDTH = 88.dp

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DeckEntryListScreen(
    deckId: Int,
    vm: DeckEntryListViewModel = koinViewModel(),
) {
    LaunchedEffect(deckId) { vm.load(deckId) }

    val entries by vm.entries.collectAsStateWithLifecycle()
    var zoneMoveTarget by remember { mutableStateOf<DeckEntryDto?>(null) }

    Scaffold(
        topBar = { TopAppBar(title = { Text("Deck Entries") }) },
    ) { padding ->
        LazyColumn(contentPadding = padding) {
            items(entries, key = { it.id }) { entry ->
                SwipeRevealRow(
                    entry = entry,
                    onMoveZone = { zoneMoveTarget = entry },
                )
                HorizontalDivider()
            }
        }
    }

    zoneMoveTarget?.let { entry ->
        val sheetState = rememberModalBottomSheetState(skipPartialExpansion = true)
        ModalBottomSheet(
            onDismissRequest = { zoneMoveTarget = null },
            sheetState = sheetState,
        ) {
            ZonePickerSheet(
                currentZone = entry.zone,
                onZoneSelected = { newZone ->
                    vm.moveToZone(deckId, entry.id, newZone)
                    zoneMoveTarget = null
                },
            )
        }
    }
}

@Composable
internal fun SwipeRevealRow(
    entry: DeckEntryDto,
    onMoveZone: () -> Unit,
) {
    val density = LocalDensity.current
    val revealPx = with(density) { REVEAL_WIDTH.toPx() }
    val scope = rememberCoroutineScope()
    val offsetX = remember(entry.id) { Animatable(0f) }

    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(IntrinsicSize.Min)
            .testTag("entry_row_${entry.id}"),
    ) {
        // Trailing action revealed by swiping left
        Box(
            modifier = Modifier
                .width(REVEAL_WIDTH)
                .fillMaxHeight()
                .align(Alignment.CenterEnd)
                .background(MaterialTheme.colorScheme.primary)
                .testTag("move_zone_button_${entry.id}"),
            contentAlignment = Alignment.Center,
        ) {
            TextButton(onClick = {
                scope.launch { offsetX.animateTo(0f, tween(200)) }
                onMoveZone()
            }) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(
                        Icons.Default.SwapHoriz,
                        contentDescription = "Move zone",
                        tint = MaterialTheme.colorScheme.onPrimary,
                    )
                    Text(
                        "Move zone",
                        color = MaterialTheme.colorScheme.onPrimary,
                        style = MaterialTheme.typography.labelSmall,
                    )
                }
            }
        }

        // Entry row — slides left to reveal the action behind it
        ListItem(
            headlineContent = { Text(entry.scryfallCard?.name ?: entry.scryfallId) },
            trailingContent = { ZoneBadge(zone = entry.zone) },
            modifier = Modifier
                .fillMaxWidth()
                .offset { IntOffset(x = offsetX.value.roundToInt(), y = 0) }
                .background(MaterialTheme.colorScheme.surface)
                .pointerInput(entry.id) {
                    // Manually discriminate horizontal vs vertical intent before
                    // consuming events, so the LazyColumn scroll is not blocked.
                    awaitEachGesture {
                        val down = awaitFirstDown(requireUnconsumed = false)
                        var cumulativeX = 0f
                        var cumulativeY = 0f
                        var horizontalDrag = false

                        while (true) {
                            val event = awaitPointerEvent()
                            val change = event.changes.firstOrNull { it.id == down.id } ?: break

                            if (change.isConsumed) break

                            val dx = change.position.x - change.previousPosition.x
                            val dy = change.position.y - change.previousPosition.y
                            cumulativeX += dx
                            cumulativeY += dy

                            if (!horizontalDrag) {
                                val touchSlop = viewConfiguration.touchSlop
                                when {
                                    // Clearly vertical — let the scroll have it
                                    abs(cumulativeY) > abs(cumulativeX) * 1.5f
                                            && abs(cumulativeY) > touchSlop -> break
                                    // Clearly horizontal — take ownership
                                    abs(cumulativeX) > abs(cumulativeY) * 1.5f
                                            && abs(cumulativeX) > touchSlop -> {
                                        horizontalDrag = true
                                    }
                                    else -> continue
                                }
                            }

                            change.consume()
                            scope.launch {
                                offsetX.snapTo(
                                    (offsetX.value + dx).coerceIn(-revealPx, 0f),
                                )
                            }

                            if (!change.pressed) {
                                // Finger lifted — snap to nearest anchor
                                scope.launch {
                                    val target = if (offsetX.value < -revealPx / 2) -revealPx else 0f
                                    offsetX.animateTo(target, tween(200))
                                }
                                break
                            }
                        }
                    }
                },
        )
    }
}

@Composable
fun ZonePickerSheet(
    currentZone: String,
    onZoneSelected: (String) -> Unit,
) {
    Column(modifier = Modifier.testTag("zone_picker_sheet")) {
        Text(
            "Move to zone",
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp),
        )
        ZONES.forEach { zone ->
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable(enabled = zone != currentZone) { onZoneSelected(zone) }
                    .padding(horizontal = 16.dp, vertical = 12.dp)
                    .testTag("zone_option_$zone"),
            ) {
                ZoneBadge(zone = zone)
                Text(
                    zone.replaceFirstChar { it.uppercase() },
                    style = MaterialTheme.typography.bodyLarge,
                    modifier = Modifier.padding(start = 12.dp),
                    color = if (zone == currentZone)
                        MaterialTheme.colorScheme.onSurface.copy(alpha = 0.38f)
                    else
                        MaterialTheme.colorScheme.onSurface,
                )
            }
        }
        Spacer(Modifier.height(16.dp))
    }
}

@Composable
fun ZoneBadge(zone: String) {
    val (label, containerColor) = when (zone) {
        "main" -> "Main" to MaterialTheme.colorScheme.primaryContainer
        "side" -> "Side" to MaterialTheme.colorScheme.secondaryContainer
        "maybe" -> "Maybe" to MaterialTheme.colorScheme.tertiaryContainer
        else -> zone to MaterialTheme.colorScheme.surfaceVariant
    }
    SuggestionChip(
        onClick = {},
        label = { Text(label, style = MaterialTheme.typography.labelSmall) },
        colors = SuggestionChipDefaults.suggestionChipColors(containerColor = containerColor),
    )
}

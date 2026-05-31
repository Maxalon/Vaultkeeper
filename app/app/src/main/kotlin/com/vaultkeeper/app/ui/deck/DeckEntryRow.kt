package com.vaultkeeper.app.ui.deck

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.vaultkeeper.app.data.api.dto.DeckEntryDto

private val ACTION_WIDTH = 88.dp
// Minimum horizontal drag before we treat it as a swipe (vs a tap or scroll).
private const val DRAG_THRESHOLD_PX = 8f

@Composable
fun DeckEntryRow(
    entry: DeckEntryDto,
    onMoveZone: (DeckZone) -> Unit,
    modifier: Modifier = Modifier,
) {
    val density = LocalDensity.current
    val actionWidthPx = with(density) { ACTION_WIDTH.toPx() }

    var rawOffset by remember { mutableFloatStateOf(0f) }
    // Snap to fully-revealed or closed once drag ends.
    val snappedOffset by animateFloatAsState(
        targetValue = rawOffset,
        animationSpec = spring(),
        label = "swipe_offset",
    )

    var showZonePicker by remember { mutableStateOf(false) }

    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(64.dp)
            .clipToBounds()
            .testTag("entry_row_${entry.id}"),
    ) {
        // "Move zone" action — sits behind the row on the left edge.
        Box(
            modifier = Modifier
                .align(Alignment.CenterStart)
                .width(ACTION_WIDTH)
                .fillMaxHeight()
                .background(MaterialTheme.colorScheme.tertiary)
                .clickable { showZonePicker = true }
                .testTag("move_zone_action_${entry.id}"),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                text = "Move\nzone",
                style = MaterialTheme.typography.labelMedium,
                fontWeight = FontWeight.SemiBold,
                color = MaterialTheme.colorScheme.onTertiary,
            )
        }

        // Row content — slides right to expose the action above.
        Surface(
            modifier = Modifier
                .fillMaxWidth()
                .fillMaxHeight()
                .graphicsLayer { translationX = snappedOffset }
                .pointerInput(Unit) {
                    var accumulated = 0f
                    detectHorizontalDragGestures(
                        onDragStart = { accumulated = 0f },
                        onDragEnd = {
                            // Snap: reveal if dragged past 40 % of action width.
                            rawOffset = if (rawOffset > actionWidthPx * 0.4f) actionWidthPx else 0f
                        },
                        onDragCancel = { rawOffset = 0f },
                        onHorizontalDrag = { change, dragAmount ->
                            accumulated += dragAmount
                            // Only consume and translate once we've passed the
                            // horizontal threshold — lets vertical scroll win for
                            // small diagonal movements.
                            if (kotlin.math.abs(accumulated) >= DRAG_THRESHOLD_PX) {
                                change.consume()
                                rawOffset = (rawOffset + dragAmount).coerceIn(0f, actionWidthPx)
                            }
                        },
                    )
                },
            color = MaterialTheme.colorScheme.surface,
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .fillMaxHeight()
                    .padding(horizontal = 16.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    text = entry.scryfall_id.take(8), // placeholder until card name is in the DTO
                    style = MaterialTheme.typography.bodyMedium,
                )
                ZoneBadge(zone = DeckZone.from(entry.zone))
            }
        }
    }

    HorizontalDivider()

    if (showZonePicker) {
        ZonePickerSheet(
            currentZone = DeckZone.from(entry.zone),
            onZoneSelected = { zone ->
                onMoveZone(zone)
                showZonePicker = false
                rawOffset = 0f
            },
            onDismiss = {
                showZonePicker = false
                rawOffset = 0f
            },
        )
    }
}

@Composable
private fun ZoneBadge(zone: DeckZone) {
    val (bg, fg) = when (zone) {
        DeckZone.MAIN -> MaterialTheme.colorScheme.primaryContainer to MaterialTheme.colorScheme.onPrimaryContainer
        DeckZone.SIDE -> MaterialTheme.colorScheme.secondaryContainer to MaterialTheme.colorScheme.onSecondaryContainer
        DeckZone.MAYBE -> MaterialTheme.colorScheme.tertiaryContainer to MaterialTheme.colorScheme.onTertiaryContainer
    }
    Box(
        modifier = Modifier
            .clip(RoundedCornerShape(4.dp))
            .background(bg)
            .padding(horizontal = 6.dp, vertical = 2.dp),
    ) {
        Text(
            text = zone.label,
            style = MaterialTheme.typography.labelSmall,
            color = fg,
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ZonePickerSheet(
    currentZone: DeckZone,
    onZoneSelected: (DeckZone) -> Unit,
    onDismiss: () -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(bottom = 24.dp)
                .testTag("zone_picker_sheet"),
        ) {
            Text(
                text = "Move to zone",
                style = MaterialTheme.typography.titleMedium,
                modifier = Modifier.padding(horizontal = 24.dp, vertical = 16.dp),
            )
            DeckZone.all.forEach { zone ->
                val isSelected = zone == currentZone
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable(enabled = !isSelected) { onZoneSelected(zone) }
                        .padding(horizontal = 24.dp, vertical = 14.dp)
                        .testTag("zone_option_${zone.apiValue}"),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Text(
                        text = zone.label,
                        style = MaterialTheme.typography.bodyLarge,
                        color = if (isSelected)
                            MaterialTheme.colorScheme.onSurface.copy(alpha = 0.38f)
                        else
                            MaterialTheme.colorScheme.onSurface,
                    )
                    if (isSelected) {
                        Text(
                            text = "current",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.38f),
                        )
                    }
                }
                if (zone != DeckZone.entries.last()) HorizontalDivider(modifier = Modifier.padding(horizontal = 24.dp))
            }
        }
    }
}

package com.vaultkeeper.app.ui.deckentry.components

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.RadioButton
import androidx.compose.material3.SheetState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.vaultkeeper.app.R

private val ZONES = listOf(
    "main" to R.string.zone_main,
    "side" to R.string.zone_side,
    "maybe" to R.string.zone_maybe,
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ZonePickerSheet(
    currentZone: String,
    sheetState: SheetState,
    onSelect: (String) -> Unit,
    onDismiss: () -> Unit,
) {
    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
    ) {
        Text(
            text = stringResource(R.string.zone_picker_title),
            style = MaterialTheme.typography.titleMedium,
            modifier = Modifier.padding(horizontal = 16.dp),
        )
        Spacer(Modifier.height(8.dp))
        HorizontalDivider()

        ZONES.forEach { (zone, labelRes) ->
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable { onSelect(zone) }
                    .padding(horizontal = 16.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                RadioButton(
                    selected = zone == currentZone,
                    onClick = { onSelect(zone) },
                )
                Text(stringResource(labelRes), modifier = Modifier.padding(start = 8.dp))
            }
        }
        Spacer(Modifier.height(16.dp))
    }
}

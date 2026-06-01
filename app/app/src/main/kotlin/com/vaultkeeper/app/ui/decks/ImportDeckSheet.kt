package com.vaultkeeper.app.ui.decks

import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import org.koin.androidx.compose.koinViewModel

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ImportDeckSheet(
    onDismiss: () -> Unit,
    onNavigateToDeck: (Long) -> Unit,
    vm: ImportDeckViewModel = koinViewModel(),
) {
    val state by vm.state.collectAsStateWithLifecycle()
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val context = LocalContext.current

    LaunchedEffect(Unit) {
        vm.navigateToDeck.collect { deckId -> onNavigateToDeck(deckId) }
    }

    val csvPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            val displayName = context.contentResolver
                .query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)
                ?.use { cursor -> if (cursor.moveToFirst()) cursor.getString(0) else "deck.csv" }
                ?: "deck.csv"
            vm.onCsvPicked(uri, displayName)
        }
    }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 16.dp)
                .padding(bottom = 24.dp)
                .imePadding(),
        ) {
            Text("Import deck", style = MaterialTheme.typography.titleLarge)
            Spacer(Modifier.height(16.dp))

            TabRow(selectedTabIndex = state.tab.ordinal) {
                Tab(
                    selected = state.tab == ImportTab.Paste,
                    onClick = { vm.onTabChange(ImportTab.Paste) },
                    text = { Text("Paste list") },
                )
                Tab(
                    selected = state.tab == ImportTab.Csv,
                    onClick = { vm.onTabChange(ImportTab.Csv) },
                    text = { Text("Upload CSV") },
                )
            }

            Spacer(Modifier.height(16.dp))

            when (state.tab) {
                ImportTab.Paste -> PasteTab(
                    name = state.name,
                    format = state.format,
                    text = state.text,
                    onNameChange = vm::onNameChange,
                    onFormatChange = vm::onFormatChange,
                    onTextChange = vm::onTextChange,
                )
                ImportTab.Csv -> CsvTab(
                    csvDisplayName = state.csvDisplayName,
                    name = state.csvName,
                    format = state.csvFormat,
                    onPickFile = { csvPicker.launch("text/csv") },
                    onNameChange = vm::onCsvNameChange,
                    onFormatChange = vm::onCsvFormatChange,
                )
            }

            val phase = state.phase
            if (phase is ImportPhase.Error) {
                Spacer(Modifier.height(8.dp))
                Text(phase.message, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
            }

            Spacer(Modifier.height(16.dp))

            Row(horizontalArrangement = Arrangement.End, modifier = Modifier.fillMaxWidth()) {
                OutlinedButton(onClick = onDismiss, enabled = phase !is ImportPhase.Submitting) {
                    Text("Cancel")
                }
                Button(
                    onClick = vm::submit,
                    enabled = vm.canSubmit(),
                    modifier = Modifier.padding(start = 8.dp),
                ) {
                    if (phase is ImportPhase.Submitting) {
                        CircularProgressIndicator(modifier = Modifier.size(20.dp))
                    } else {
                        Text("Import")
                    }
                }
            }
        }
    }
}

@Composable
private fun PasteTab(
    name: String,
    format: String,
    text: String,
    onNameChange: (String) -> Unit,
    onFormatChange: (String) -> Unit,
    onTextChange: (String) -> Unit,
) {
    OutlinedTextField(
        value = name,
        onValueChange = onNameChange,
        label = { Text("Deck name") },
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )
    Spacer(Modifier.height(8.dp))
    FormatDropdown(selected = format, onSelect = onFormatChange)
    Spacer(Modifier.height(8.dp))
    OutlinedTextField(
        value = text,
        onValueChange = onTextChange,
        label = { Text("Decklist") },
        placeholder = { Text("1 Sol Ring\n1 Command Tower\n…") },
        minLines = 8,
        maxLines = 16,
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun CsvTab(
    csvDisplayName: String,
    name: String,
    format: String,
    onPickFile: () -> Unit,
    onNameChange: (String) -> Unit,
    onFormatChange: (String) -> Unit,
) {
    OutlinedButton(onClick = onPickFile, modifier = Modifier.fillMaxWidth()) {
        Text(if (csvDisplayName.isBlank()) "Choose CSV file…" else csvDisplayName)
    }
    Spacer(Modifier.height(8.dp))
    OutlinedTextField(
        value = name,
        onValueChange = onNameChange,
        label = { Text("Deck name") },
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )
    Spacer(Modifier.height(8.dp))
    FormatDropdown(selected = format, onSelect = onFormatChange)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FormatDropdown(selected: String, onSelect: (String) -> Unit) {
    var expanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = selected,
            onValueChange = {},
            readOnly = true,
            label = { Text("Format") },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded) },
            modifier = Modifier
                .fillMaxWidth()
                .menuAnchor(MenuAnchorType.PrimaryNotEditable),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            DECK_FORMATS.forEach { fmt ->
                DropdownMenuItem(
                    text = { Text(fmt) },
                    onClick = {
                        onSelect(fmt)
                        expanded = false
                    },
                    contentPadding = ExposedDropdownMenuDefaults.ItemContentPadding,
                )
            }
        }
    }
}

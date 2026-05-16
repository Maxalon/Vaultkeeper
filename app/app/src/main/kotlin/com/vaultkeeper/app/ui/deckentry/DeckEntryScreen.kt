package com.vaultkeeper.app.ui.deckentry

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.R
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.ui.deckentry.components.CardSearchSheet
import com.vaultkeeper.app.ui.deckentry.components.EntryRow
import com.vaultkeeper.app.ui.deckentry.components.RemoveEntryDialog
import com.vaultkeeper.app.ui.deckentry.components.ZonePickerSheet
import kotlinx.coroutines.launch
import org.koin.androidx.compose.koinViewModel
import org.koin.core.parameter.parametersOf

private val ZONE_ORDER = listOf("main", "side", "maybe")

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DeckEntryScreen(
    deckId: Long,
    onNavigateUp: () -> Unit,
    vm: DeckEntryViewModel = koinViewModel(parameters = { parametersOf(deckId) }),
) {
    val state by vm.uiState.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()

    // Sheet states
    val addCardSheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val commanderSheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val zoneSheetState = rememberModalBottomSheetState()

    var showAddSheet by rememberSaveable { mutableStateOf(false) }
    var showCommanderSheet by rememberSaveable { mutableStateOf(false) }
    var entryForZone by remember { mutableStateOf<DeckEntryDto?>(null) }
    var entryToRemove by remember { mutableStateOf<DeckEntryDto?>(null) }

    LaunchedEffect(Unit) {
        vm.errorEvent.collect { msg ->
            snackbar.showSnackbar(msg)
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(state.deckName) },
                navigationIcon = {
                    IconButton(onClick = onNavigateUp) {
                        Icon(
                            Icons.Default.ArrowBack,
                            contentDescription = stringResource(R.string.navigate_up),
                        )
                    }
                },
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = {
                vm.clearSearch()
                showAddSheet = true
            }) {
                Icon(Icons.Default.Add, contentDescription = stringResource(R.string.add_card))
            }
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding),
        ) {
            if (state.isLoading) {
                CircularProgressIndicator(modifier = Modifier.align(Alignment.Center))
            } else {
                val isCommanderFormat = state.format in listOf("commander", "oathbreaker")
                val hasCommander = state.commander1 != null

                LazyColumn(modifier = Modifier.fillMaxSize()) {
                    // Commander prompt
                    if (isCommanderFormat && !hasCommander) {
                        item {
                            CommanderPromptBanner(
                                onSetCommander = {
                                    vm.clearSearch()
                                    showCommanderSheet = true
                                },
                            )
                        }
                    }

                    // Entries grouped by zone
                    ZONE_ORDER.forEach { zone ->
                        val zoneEntries = state.entries.filter { it.zone == zone }
                        if (zoneEntries.isNotEmpty()) {
                            item(key = "header_$zone") {
                                ZoneSectionHeader(zone = zone, count = zoneEntries.sumOf { it.quantity })
                            }
                            items(zoneEntries, key = { it.id }) { entry ->
                                EntryRow(
                                    entry = entry,
                                    onIncrement = { vm.increment(entry) },
                                    onDecrement = { vm.decrement(entry) },
                                    onRemove = { entryToRemove = entry },
                                    onZoneClick = { entryForZone = entry },
                                )
                                HorizontalDivider()
                            }
                        }
                    }

                    item { Spacer(Modifier.height(80.dp)) }  // FAB clearance
                }
            }
        }
    }

    // Remove confirmation dialog
    entryToRemove?.let { entry ->
        RemoveEntryDialog(
            cardName = entry.card?.name ?: entry.scryfallId,
            onConfirm = {
                vm.removeEntry(entry)
                entryToRemove = null
            },
            onDismiss = { entryToRemove = null },
        )
    }

    // Zone picker sheet
    entryForZone?.let { entry ->
        ZonePickerSheet(
            currentZone = entry.zone,
            sheetState = zoneSheetState,
            onSelect = { newZone ->
                vm.moveToZone(entry, newZone)
                scope.launch { zoneSheetState.hide() }.invokeOnCompletion {
                    entryForZone = null
                }
            },
            onDismiss = { entryForZone = null },
        )
    }

    // Add card sheet
    if (showAddSheet) {
        CardSearchSheet(
            sheetState = addCardSheetState,
            results = state.searchResults,
            isSearching = state.isSearching,
            commanderMode = false,
            onSearch = vm::searchCards,
            onSelect = { card ->
                vm.addCard(card.scryfallId)
                scope.launch { addCardSheetState.hide() }.invokeOnCompletion {
                    showAddSheet = false
                }
            },
            onDismiss = {
                showAddSheet = false
                vm.clearSearch()
            },
        )
    }

    // Commander picker sheet
    if (showCommanderSheet) {
        CardSearchSheet(
            sheetState = commanderSheetState,
            results = state.searchResults,
            isSearching = state.isSearching,
            commanderMode = true,
            onSearch = vm::searchCards,
            onSelect = { card ->
                vm.setCommander(card.scryfallId)
                scope.launch { commanderSheetState.hide() }.invokeOnCompletion {
                    showCommanderSheet = false
                }
            },
            onDismiss = {
                showCommanderSheet = false
                vm.clearSearch()
            },
        )
    }
}

@Composable
private fun ZoneSectionHeader(zone: String, count: Int) {
    val labelRes = when (zone) {
        "main" -> R.string.zone_main
        "side" -> R.string.zone_side
        "maybe" -> R.string.zone_maybe
        else -> null
    }
    val label = if (labelRes != null) stringResource(labelRes) else zone
    Text(
        text = "$label ($count)",
        style = MaterialTheme.typography.labelMedium,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 8.dp),
    )
}

@Composable
private fun CommanderPromptBanner(onSetCommander: () -> Unit) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .padding(16.dp),
        colors = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.secondaryContainer,
        ),
    ) {
        Column(
            modifier = Modifier
                .padding(16.dp)
                .fillMaxWidth(),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = stringResource(R.string.commander_prompt_title),
                style = MaterialTheme.typography.titleSmall,
            )
            Spacer(Modifier.height(8.dp))
            Button(onClick = onSetCommander) {
                Text(stringResource(R.string.set_commander))
            }
        }
    }
}

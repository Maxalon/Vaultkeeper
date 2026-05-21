package com.vaultkeeper.app.ui.deck

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.BottomSheetDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Scaffold
import androidx.compose.material3.ScrollableTabRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import com.vaultkeeper.app.R
import com.vaultkeeper.app.data.api.dto.CommanderCardDto
import com.vaultkeeper.app.data.api.dto.CompanionCardDto
import com.vaultkeeper.app.data.api.dto.DeckDetailResponse
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.IllegalityDto
import org.koin.androidx.compose.koinViewModel

// ─── Entry point ─────────────────────────────────────────────────────────────

@Composable
fun DeckDetailScreen(
    deckId: Long,
    onBack: () -> Unit,
    vm: DeckDetailViewModel = koinViewModel(),
) {
    LaunchedEffect(deckId) { vm.init(deckId) }
    val state by vm.uiState.collectAsStateWithLifecycle()
    DeckDetailContent(
        state = state,
        onBack = onBack,
        onTabSelected = vm::selectTab,
        onEntrySelected = vm::selectEntry,
        onDeckInfoToggle = vm::toggleDeckInfo,
        onRetry = vm::retry,
    )
}

// ─── Stateless shell ─────────────────────────────────────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DeckDetailContent(
    state: DeckDetailUiState,
    onBack: () -> Unit,
    onTabSelected: (DeckTab) -> Unit,
    onEntrySelected: (Long?) -> Unit,
    onDeckInfoToggle: () -> Unit,
    onRetry: () -> Unit,
) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = state.deck?.name ?: stringResource(R.string.deck_detail_loading),
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = stringResource(R.string.nav_back))
                    }
                },
                actions = {
                    IconButton(onClick = onDeckInfoToggle) {
                        Icon(Icons.Default.Info, contentDescription = stringResource(R.string.deck_info))
                    }
                },
            )
        },
        floatingActionButton = {
            if (!state.isLoading && state.error == null) {
                FloatingActionButton(onClick = { /* Catalog Search — separate ticket */ }) {
                    Icon(Icons.Default.Add, contentDescription = stringResource(R.string.deck_add_card))
                }
            }
        },
    ) { innerPadding ->
        when {
            state.isLoading -> LoadingPane(Modifier.padding(innerPadding))
            state.error != null -> ErrorPane(state.error, onRetry, Modifier.padding(innerPadding))
            state.deck != null -> DeckBody(
                deck = state.deck,
                entries = state.entries,
                illegalities = state.illegalities,
                illegalScryfallIds = state.illegalScryfallIds,
                selectedTab = state.selectedTab,
                selectedEntryId = state.selectedEntryId,
                onTabSelected = onTabSelected,
                onEntrySelected = onEntrySelected,
                modifier = Modifier.padding(innerPadding),
            )
        }
    }

    // Deck info bottom sheet
    if (state.showDeckInfoSheet && state.deck != null) {
        DeckInfoSheet(
            deck = state.deck,
            entries = state.entries,
            onDismiss = onDeckInfoToggle,
        )
    }
}

// ─── Deck body ────────────────────────────────────────────────────────────────

@Composable
private fun DeckBody(
    deck: DeckDetailResponse,
    entries: List<DeckEntryDto>,
    illegalities: List<IllegalityDto>,
    illegalScryfallIds: Set<String>,
    selectedTab: DeckTab,
    selectedEntryId: Long?,
    onTabSelected: (DeckTab) -> Unit,
    onEntrySelected: (Long?) -> Unit,
    modifier: Modifier = Modifier,
) {
    val nonIgnoredCount = remember(illegalities) { illegalities.count { !it.ignored } }
    val selectedEntry = remember(selectedEntryId, entries) {
        entries.find { it.id == selectedEntryId }
    }

    Column(modifier = modifier.fillMaxSize()) {
        CommanderZone(deck)
        DeckTabRow(selectedTab, nonIgnoredCount, onTabSelected)
        HorizontalDivider()
        when (selectedTab) {
            DeckTab.MAINBOARD -> EntryListContent(
                entries = entries,
                illegalScryfallIds = illegalScryfallIds,
                onEntrySelected = onEntrySelected,
                modifier = Modifier.weight(1f),
            )
            else -> PlaceholderContent(selectedTab, Modifier.weight(1f))
        }
    }

    if (selectedEntry != null) {
        EntryDetailSheet(entry = selectedEntry, onDismiss = { onEntrySelected(null) })
    }
}

// ─── Commander zone ───────────────────────────────────────────────────────────

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun CommanderZone(deck: DeckDetailResponse) {
    if (deck.commander1 == null && deck.commander2 == null && deck.companion == null) return

    Surface(
        color = MaterialTheme.colorScheme.surfaceVariant,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            deck.commander1?.let { CommanderSlot(it) }
            deck.commander2?.let { CommanderSlot(it) }
            deck.companion?.let { CompanionSlot(it) }

            if (deck.color_identity.isNotEmpty()) {
                Spacer(Modifier.weight(1f))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    deck.color_identity.forEach { color ->
                        ManaPip(symbol = color, size = 20.dp)
                    }
                }
            }
        }
    }
}

@Composable
private fun CommanderSlot(card: CommanderCardDto) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        AsyncImage(
            model = card.image_small,
            contentDescription = card.name,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .height(56.dp)
                .aspectRatio(63f / 88f)
                .clip(RoundedCornerShape(4.dp)),
        )
        Column {
            Text(
                text = card.name,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.width(120.dp),
            )
            if (card.commander_game_changer) {
                Text(
                    text = stringResource(R.string.deck_game_changer),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.tertiary,
                )
            }
        }
    }
}

@Composable
private fun CompanionSlot(card: CompanionCardDto) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        AsyncImage(
            model = card.image_small,
            contentDescription = card.name,
            contentScale = ContentScale.Crop,
            modifier = Modifier
                .height(56.dp)
                .aspectRatio(63f / 88f)
                .clip(RoundedCornerShape(4.dp)),
        )
        Column {
            Text(
                text = card.name,
                style = MaterialTheme.typography.bodyMedium,
                fontWeight = FontWeight.SemiBold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.width(120.dp),
            )
            Text(
                text = stringResource(R.string.deck_companion),
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.secondary,
            )
        }
    }
}

// ─── Tab row ──────────────────────────────────────────────────────────────────

@Composable
private fun DeckTabRow(
    selected: DeckTab,
    illegalityCount: Int,
    onSelected: (DeckTab) -> Unit,
) {
    val tabs = DeckTab.entries
    ScrollableTabRow(selectedTabIndex = tabs.indexOf(selected), edgePadding = 0.dp) {
        tabs.forEach { tab ->
            Tab(
                selected = tab == selected,
                onClick = { onSelected(tab) },
                text = {
                    if (tab == DeckTab.ILLEGALITIES && illegalityCount > 0) {
                        BadgedBox(badge = {
                            Badge { Text(illegalityCount.toString()) }
                        }) {
                            Text(tabLabel(tab))
                        }
                    } else {
                        Text(tabLabel(tab))
                    }
                },
            )
        }
    }
}

@Composable
private fun tabLabel(tab: DeckTab): String = when (tab) {
    DeckTab.MAINBOARD -> stringResource(R.string.tab_mainboard)
    DeckTab.ANALYSIS -> stringResource(R.string.tab_analysis)
    DeckTab.ILLEGALITIES -> stringResource(R.string.tab_illegalities)
    DeckTab.PHYSICAL -> stringResource(R.string.tab_physical)
    DeckTab.WANTED -> stringResource(R.string.tab_wanted)
}

// ─── Entry list ───────────────────────────────────────────────────────────────

@Composable
private fun EntryListContent(
    entries: List<DeckEntryDto>,
    illegalScryfallIds: Set<String>,
    onEntrySelected: (Long) -> Unit,
    modifier: Modifier = Modifier,
) {
    // Group entries into display zones
    val commanders = remember(entries) { entries.filter { it.is_commander } }
    val mainboard = remember(entries) { entries.filter { it.zone == "main" && !it.is_commander } }
    val sideboard = remember(entries) { entries.filter { it.zone == "side" } }
    val maybeboard = remember(entries) { entries.filter { it.zone == "maybe" } }

    // Per-zone collapsed state, default expanded
    val collapsed = remember { mutableStateMapOf<String, Boolean>() }

    LazyColumn(modifier = modifier.fillMaxSize()) {
        if (commanders.isNotEmpty()) {
            item(key = "header_commander") {
                ZoneSectionHeader(
                    label = stringResource(R.string.zone_commander),
                    count = commanders.size,
                    collapsed = collapsed["commander"] == true,
                    onToggle = { collapsed["commander"] = collapsed["commander"] != true },
                )
            }
            if (collapsed["commander"] != true) {
                items(commanders, key = { it.id }) { entry ->
                    EntryRow(
                        entry = entry,
                        isIllegal = illegalScryfallIds.contains(entry.scryfall_id),
                        onClick = { onEntrySelected(entry.id) },
                    )
                }
            }
        }

        if (mainboard.isNotEmpty()) {
            item(key = "header_main") {
                ZoneSectionHeader(
                    label = stringResource(R.string.zone_mainboard),
                    count = mainboard.sumOf { it.quantity },
                    collapsed = collapsed["main"] == true,
                    onToggle = { collapsed["main"] = collapsed["main"] != true },
                )
            }
            if (collapsed["main"] != true) {
                items(mainboard, key = { it.id }) { entry ->
                    EntryRow(
                        entry = entry,
                        isIllegal = illegalScryfallIds.contains(entry.scryfall_id),
                        onClick = { onEntrySelected(entry.id) },
                    )
                }
            }
        }

        if (sideboard.isNotEmpty()) {
            item(key = "header_side") {
                ZoneSectionHeader(
                    label = stringResource(R.string.zone_sideboard),
                    count = sideboard.sumOf { it.quantity },
                    collapsed = collapsed["side"] == true,
                    onToggle = { collapsed["side"] = collapsed["side"] != true },
                )
            }
            if (collapsed["side"] != true) {
                items(sideboard, key = { it.id }) { entry ->
                    EntryRow(
                        entry = entry,
                        isIllegal = illegalScryfallIds.contains(entry.scryfall_id),
                        onClick = { onEntrySelected(entry.id) },
                    )
                }
            }
        }

        if (maybeboard.isNotEmpty()) {
            item(key = "header_maybe") {
                ZoneSectionHeader(
                    label = stringResource(R.string.zone_maybeboard),
                    count = maybeboard.sumOf { it.quantity },
                    collapsed = collapsed["maybe"] == true,
                    onToggle = { collapsed["maybe"] = collapsed["maybe"] != true },
                )
            }
            if (collapsed["maybe"] != true) {
                items(maybeboard, key = { it.id }) { entry ->
                    EntryRow(
                        entry = entry,
                        isIllegal = illegalScryfallIds.contains(entry.scryfall_id),
                        onClick = { onEntrySelected(entry.id) },
                    )
                }
            }
        }

        item { Spacer(Modifier.height(80.dp)) } // FAB clearance
    }
}

@Composable
private fun ZoneSectionHeader(
    label: String,
    count: Int,
    collapsed: Boolean,
    onToggle: () -> Unit,
) {
    Surface(color = MaterialTheme.colorScheme.surfaceContainer) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .clickable(onClick = onToggle)
                .padding(horizontal = 16.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = label,
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.Bold,
                modifier = Modifier.weight(1f),
            )
            Text(
                text = count.toString(),
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.width(8.dp))
            Icon(
                imageVector = if (collapsed) Icons.Default.KeyboardArrowDown else Icons.Default.KeyboardArrowUp,
                contentDescription = if (collapsed) stringResource(R.string.expand) else stringResource(R.string.collapse),
                modifier = Modifier.size(20.dp),
            )
        }
    }
}

@Composable
private fun EntryRow(
    entry: DeckEntryDto,
    isIllegal: Boolean,
    onClick: () -> Unit,
) {
    val card = entry.scryfall_card
    ListItem(
        modifier = Modifier.clickable(onClick = onClick),
        headlineContent = {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(
                    text = card?.name ?: entry.scryfall_id,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                if (isIllegal) {
                    Icon(
                        Icons.Default.Warning,
                        contentDescription = stringResource(R.string.entry_illegal),
                        tint = MaterialTheme.colorScheme.error,
                        modifier = Modifier.size(16.dp),
                    )
                }
            }
        },
        supportingContent = {
            if (card != null) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    if (!card.mana_cost.isNullOrEmpty()) {
                        ManaCostRow(card.mana_cost)
                    }
                    if (!card.set_code.isNullOrEmpty()) {
                        Text(
                            text = card.set_code.uppercase(),
                            style = MaterialTheme.typography.labelSmall,
                            color = rarityColor(card.rarity),
                        )
                    }
                }
            }
        },
        leadingContent = {
            QuantityBadge(entry.quantity)
        },
        trailingContent = {
            Row(horizontalArrangement = Arrangement.spacedBy(4.dp), verticalAlignment = Alignment.CenterVertically) {
                if (entry.foil || entry.is_etched) {
                    FoilBadge(entry.is_etched)
                }
            }
        },
    )
    HorizontalDivider(thickness = 0.5.dp, color = MaterialTheme.colorScheme.outlineVariant)
}

@Composable
private fun QuantityBadge(qty: Int) {
    Box(
        contentAlignment = Alignment.Center,
        modifier = Modifier
            .size(28.dp)
            .clip(CircleShape)
            .background(MaterialTheme.colorScheme.primaryContainer),
    ) {
        Text(
            text = qty.toString(),
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onPrimaryContainer,
            fontWeight = FontWeight.Bold,
        )
    }
}

@Composable
private fun FoilBadge(isEtched: Boolean) {
    val label = if (isEtched) "E" else "F"
    Box(
        contentAlignment = Alignment.Center,
        modifier = Modifier
            .size(20.dp)
            .clip(RoundedCornerShape(3.dp))
            .background(MaterialTheme.colorScheme.tertiaryContainer),
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.labelSmall,
            color = MaterialTheme.colorScheme.onTertiaryContainer,
            fontWeight = FontWeight.Bold,
        )
    }
}

// ─── Mana cost / pip display ──────────────────────────────────────────────────

@Composable
private fun ManaCostRow(manaCost: String) {
    val symbols = parseManaSymbols(manaCost)
    Row(horizontalArrangement = Arrangement.spacedBy(2.dp)) {
        symbols.forEach { sym -> ManaPip(symbol = sym, size = 14.dp) }
    }
}

@Composable
private fun ManaPip(symbol: String, size: androidx.compose.ui.unit.Dp) {
    Box(
        contentAlignment = Alignment.Center,
        modifier = Modifier
            .size(size)
            .clip(CircleShape)
            .background(manaSymbolColor(symbol)),
    ) {
        val text = when (symbol.uppercase()) {
            "W", "U", "B", "R", "G", "C" -> symbol.uppercase()
            else -> symbol.take(2)
        }
        Text(
            text = text,
            fontSize = (size.value * 0.5f).sp,
            color = if (symbol.uppercase() == "U" || symbol.uppercase() == "B") Color.White else Color.Black,
            fontWeight = FontWeight.Bold,
            lineHeight = (size.value * 0.5f).sp,
        )
    }
}

private fun parseManaSymbols(cost: String): List<String> {
    val result = mutableListOf<String>()
    var i = 0
    while (i < cost.length) {
        if (cost[i] == '{') {
            val end = cost.indexOf('}', i)
            if (end != -1) {
                result.add(cost.substring(i + 1, end))
                i = end + 1
            } else {
                i++
            }
        } else {
            i++
        }
    }
    return result
}

private fun manaSymbolColor(symbol: String): Color = when (symbol.uppercase()) {
    "W" -> Color(0xFFF9FAF4)
    "U" -> Color(0xFF0E68AB)
    "B" -> Color(0xFF150B00)
    "R" -> Color(0xFFD3202A)
    "G" -> Color(0xFF00733E)
    "C" -> Color(0xFFCBD0DA)
    else -> Color(0xFFAAAAAA)
}

@Composable
private fun rarityColor(rarity: String?): Color = when (rarity) {
    "uncommon" -> MaterialTheme.colorScheme.secondary
    "rare" -> Color(0xFFD4AF37)
    "mythic" -> Color(0xFFE07030)
    else -> MaterialTheme.colorScheme.onSurfaceVariant
}

// ─── Entry detail bottom sheet ────────────────────────────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EntryDetailSheet(entry: DeckEntryDto, onDismiss: () -> Unit) {
    val sheetState = rememberModalBottomSheetState()
    val card = entry.scryfall_card

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
        dragHandle = { BottomSheetDefaults.DragHandle() },
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 20.dp)
                .padding(bottom = 32.dp),
        ) {
            if (card != null) {
                Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                    AsyncImage(
                        model = card.image_normal ?: card.image_small,
                        contentDescription = card.name,
                        contentScale = ContentScale.FillWidth,
                        modifier = Modifier
                            .width(120.dp)
                            .aspectRatio(63f / 88f)
                            .clip(RoundedCornerShape(8.dp)),
                    )
                    Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(card.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        if (!card.mana_cost.isNullOrEmpty()) {
                            ManaCostRow(card.mana_cost)
                        }
                        if (!card.type_line.isNullOrEmpty()) {
                            Text(card.type_line, style = MaterialTheme.typography.bodySmall)
                        }
                        if (!card.set_code.isNullOrEmpty()) {
                            Text(
                                text = "${card.set_code.uppercase()} #${card.collector_number ?: "?"}",
                                style = MaterialTheme.typography.labelSmall,
                                color = rarityColor(card.rarity),
                            )
                        }
                        if (entry.physical_copy_id != null) {
                            Text(
                                text = stringResource(R.string.entry_bound),
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.primary,
                            )
                        }
                    }
                }
                if (!card.oracle_text.isNullOrEmpty()) {
                    Spacer(Modifier.height(12.dp))
                    HorizontalDivider()
                    Spacer(Modifier.height(12.dp))
                    Text(
                        text = card.oracle_text,
                        style = MaterialTheme.typography.bodySmall,
                        fontStyle = FontStyle.Italic,
                    )
                }
            } else {
                Text(
                    text = entry.scryfall_id,
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

// ─── Deck info bottom sheet ───────────────────────────────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun DeckInfoSheet(
    deck: DeckDetailResponse,
    entries: List<DeckEntryDto>,
    onDismiss: () -> Unit,
) {
    val sheetState = rememberModalBottomSheetState()
    val isAssembled = remember(entries) { entries.any { it.physical_copy_id != null } }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
        dragHandle = { BottomSheetDefaults.DragHandle() },
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 20.dp)
                .padding(bottom = 32.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Text(deck.name, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                InfoChip(deck.format.uppercase())
                InfoChip(
                    if (isAssembled) stringResource(R.string.deck_assembled) else stringResource(R.string.deck_unassembled)
                )
                if (deck.is_archived) InfoChip(stringResource(R.string.deck_archived))
            }
            if (!deck.description.isNullOrEmpty()) {
                Spacer(Modifier.height(4.dp))
                Text(deck.description, style = MaterialTheme.typography.bodyMedium)
            }
        }
    }
}

@Composable
private fun InfoChip(label: String) {
    Surface(
        color = MaterialTheme.colorScheme.secondaryContainer,
        shape = RoundedCornerShape(16.dp),
    ) {
        Text(
            text = label,
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSecondaryContainer,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
        )
    }
}

// ─── Placeholder tab ─────────────────────────────────────────────────────────

@Composable
private fun PlaceholderContent(tab: DeckTab, modifier: Modifier = Modifier) {
    Box(modifier = modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        Text(
            text = stringResource(R.string.tab_coming_soon, tabLabel(tab)),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

// ─── Loading / error ──────────────────────────────────────────────────────────

@Composable
private fun LoadingPane(modifier: Modifier = Modifier) {
    Box(modifier = modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        CircularProgressIndicator()
    }
}

@Composable
private fun ErrorPane(message: String, onRetry: () -> Unit, modifier: Modifier = Modifier) {
    Column(
        modifier = modifier.fillMaxSize(),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(
            text = message,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.error,
        )
        Spacer(Modifier.height(16.dp))
        TextButton(onClick = onRetry) {
            Text(stringResource(R.string.retry))
        }
    }
}

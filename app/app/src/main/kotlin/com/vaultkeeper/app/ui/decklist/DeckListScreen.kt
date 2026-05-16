package com.vaultkeeper.app.ui.decklist

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Badge
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.R
import com.vaultkeeper.app.data.api.dto.DeckDto
import org.koin.androidx.compose.koinViewModel

private val FORMATS = listOf("commander", "oathbreaker", "pauper", "standard", "modern")

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DeckListScreen(
    onDeckClick: (Long) -> Unit,
    vm: DeckListViewModel = koinViewModel(),
) {
    val s by vm.state.collectAsStateWithLifecycle()

    if (s.pendingDelete != null) {
        AlertDialog(
            onDismissRequest = vm::cancelDelete,
            title = { Text(stringResource(R.string.deck_delete_title)) },
            text = { Text(stringResource(R.string.deck_delete_body, s.pendingDelete!!.name)) },
            confirmButton = {
                TextButton(onClick = vm::confirmDelete) {
                    Text(
                        stringResource(R.string.deck_delete_confirm),
                        color = MaterialTheme.colorScheme.error,
                    )
                }
            },
            dismissButton = {
                TextButton(onClick = vm::cancelDelete) {
                    Text(stringResource(R.string.cancel))
                }
            },
        )
    }

    if (s.showCreateSheet) {
        ModalBottomSheet(onDismissRequest = vm::closeCreateSheet) {
            CreateDeckSheet(
                name = s.createName,
                format = s.createFormat,
                creating = s.creating,
                error = s.createError,
                onNameChange = vm::onCreateNameChange,
                onFormatChange = vm::onCreateFormatChange,
                onSubmit = vm::submitCreate,
                onCancel = vm::closeCreateSheet,
            )
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.decklist_title)) },
                actions = {
                    TextButton(onClick = vm::logout) {
                        Text(stringResource(R.string.home_logout))
                    }
                },
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = vm::openCreateSheet) {
                Icon(Icons.Default.Add, contentDescription = stringResource(R.string.decklist_create))
            }
        },
    ) { padding ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding),
        ) {
            when {
                s.loading -> CircularProgressIndicator(Modifier.align(Alignment.Center))
                s.error != null -> {
                    Column(
                        modifier = Modifier
                            .align(Alignment.Center)
                            .padding(24.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Text(s.error!!, color = MaterialTheme.colorScheme.error)
                        Spacer(Modifier.height(12.dp))
                        Button(onClick = vm::load) { Text(stringResource(R.string.retry)) }
                    }
                }
                s.decks.isEmpty() -> {
                    Text(
                        stringResource(R.string.decklist_empty),
                        modifier = Modifier.align(Alignment.Center),
                        style = MaterialTheme.typography.bodyLarge,
                    )
                }
                else -> {
                    LazyColumn(contentPadding = PaddingValues(vertical = 8.dp)) {
                        items(s.decks, key = { it.id }) { deck ->
                            DeckRow(
                                deck = deck,
                                onClick = { onDeckClick(deck.id) },
                                onDelete = { vm.requestDelete(deck) },
                            )
                        }
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class, ExperimentalMaterial3Api::class)
@Composable
private fun DeckRow(
    deck: DeckDto,
    onClick: () -> Unit,
    onDelete: () -> Unit,
) {
    val dismissState = rememberSwipeToDismissBoxState(
        confirmValueChange = { value ->
            if (value == SwipeToDismissBoxValue.EndToStart) {
                onDelete()
            }
            // Never auto-dismiss: the confirmation dialog controls removal.
            false
        },
    )

    SwipeToDismissBox(
        state = dismissState,
        enableDismissFromStartToEnd = false,
        backgroundContent = {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(horizontal = 16.dp),
                contentAlignment = Alignment.CenterEnd,
            ) {
                Icon(Icons.Default.Delete, contentDescription = null, tint = MaterialTheme.colorScheme.error)
            }
        },
    ) {
        ListItem(
            modifier = Modifier.combinedClickable(onClick = onClick, onLongClick = onDelete),
            headlineContent = { Text(deck.name) },
            supportingContent = {
                val commanders = listOfNotNull(deck.commander1?.name, deck.commander2?.name)
                val label = if (commanders.isNotEmpty()) commanders.joinToString(" / ") else deck.format.uppercase()
                Text(label)
            },
            trailingContent = {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (deck.isAssembled) {
                        Badge(containerColor = MaterialTheme.colorScheme.tertiary) {
                            Text(stringResource(R.string.deck_badge_assembled))
                        }
                    }
                    Badge(containerColor = MaterialTheme.colorScheme.secondaryContainer) {
                        Text(deck.format.take(4).uppercase())
                    }
                }
            },
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CreateDeckSheet(
    name: String,
    format: String,
    creating: Boolean,
    error: String?,
    onNameChange: (String) -> Unit,
    onFormatChange: (String) -> Unit,
    onSubmit: () -> Unit,
    onCancel: () -> Unit,
) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 24.dp)
            .padding(bottom = 32.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(stringResource(R.string.decklist_create_title), style = MaterialTheme.typography.titleLarge)

        OutlinedTextField(
            value = name,
            onValueChange = onNameChange,
            label = { Text(stringResource(R.string.decklist_create_name)) },
            singleLine = true,
            enabled = !creating,
            modifier = Modifier.fillMaxWidth(),
        )

        var expanded by remember { mutableStateOf(false) }
        ExposedDropdownMenuBox(
            expanded = expanded,
            onExpandedChange = { expanded = !expanded },
        ) {
            OutlinedTextField(
                value = format.replaceFirstChar { it.uppercase() },
                onValueChange = {},
                readOnly = true,
                label = { Text(stringResource(R.string.decklist_create_format)) },
                trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
                modifier = Modifier
                    .menuAnchor()
                    .fillMaxWidth(),
            )
            ExposedDropdownMenu(
                expanded = expanded,
                onDismissRequest = { expanded = false },
            ) {
                FORMATS.forEach { f ->
                    DropdownMenuItem(
                        text = { Text(f.replaceFirstChar { it.uppercase() }) },
                        onClick = { onFormatChange(f); expanded = false },
                    )
                }
            }
        }

        if (error != null) {
            Text(error, color = MaterialTheme.colorScheme.error)
        }

        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            OutlinedButton(
                onClick = onCancel,
                enabled = !creating,
                modifier = Modifier.weight(1f),
            ) {
                Text(stringResource(R.string.cancel))
            }
            Button(
                onClick = onSubmit,
                enabled = name.isNotBlank() && !creating,
                modifier = Modifier.weight(1f),
            ) {
                if (creating) {
                    CircularProgressIndicator(modifier = Modifier.size(20.dp))
                } else {
                    Text(stringResource(R.string.decklist_create_submit))
                }
            }
        }
    }
}

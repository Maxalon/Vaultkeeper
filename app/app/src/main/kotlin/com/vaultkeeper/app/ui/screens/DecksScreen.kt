package com.vaultkeeper.app.ui.screens

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.Scaffold
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.res.stringResource
import com.vaultkeeper.app.R
import com.vaultkeeper.app.ui.decks.ImportDeckSheet

@Composable
fun DecksScreen(onNavigateToDeck: (Long) -> Unit = {}) {
    var showImportSheet by remember { mutableStateOf(false) }

    Scaffold(
        floatingActionButton = {
            FloatingActionButton(onClick = { showImportSheet = true }) {
                Icon(Icons.Default.Add, contentDescription = stringResource(R.string.home_import_deck))
            }
        },
    ) { _ -> }

    if (showImportSheet) {
        ImportDeckSheet(
            onDismiss = { showImportSheet = false },
            onNavigateToDeck = { deckId ->
                showImportSheet = false
                onNavigateToDeck(deckId)
            },
        )
    }
}

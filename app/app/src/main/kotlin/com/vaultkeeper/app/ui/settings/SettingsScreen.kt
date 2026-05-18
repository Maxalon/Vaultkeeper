package com.vaultkeeper.app.ui.settings

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.BuildConfig
import com.vaultkeeper.app.R
import org.koin.androidx.compose.koinViewModel

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(
    onBack: () -> Unit,
    vm: SettingsViewModel = koinViewModel(),
) {
    val s by vm.state.collectAsStateWithLifecycle()
    val snackbarHost = remember { SnackbarHostState() }

    LaunchedEffect(s.snackbar) {
        val msg = s.snackbar ?: return@LaunchedEffect
        snackbarHost.showSnackbar(msg)
        vm.snackbarShown()
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbarHost) },
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.settings_title)) },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(
                            Icons.Filled.ArrowBack,
                            contentDescription = stringResource(R.string.settings_back),
                        )
                    }
                },
            )
        },
    ) { padding ->
        if (s.loading) {
            Box(
                modifier = Modifier.fillMaxSize().padding(padding),
                contentAlignment = Alignment.Center,
            ) {
                CircularProgressIndicator()
            }
            return@Scaffold
        }

        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState()),
        ) {
            SectionLabel(stringResource(R.string.settings_privacy_title))

            ListItem(
                headlineContent = { Text(stringResource(R.string.settings_collection_visibility)) },
                trailingContent = {
                    SingleChoiceSegmentedButtonRow {
                        SegmentedButton(
                            selected = s.collectionVisibility == "friends",
                            onClick = { vm.setCollectionVisibility("friends") },
                            shape = SegmentedButtonDefaults.itemShape(0, 2),
                            enabled = !s.saving,
                            label = { Text(stringResource(R.string.settings_friends)) },
                        )
                        SegmentedButton(
                            selected = s.collectionVisibility == "private",
                            onClick = { vm.setCollectionVisibility("private") },
                            shape = SegmentedButtonDefaults.itemShape(1, 2),
                            enabled = !s.saving,
                            label = { Text(stringResource(R.string.settings_private)) },
                        )
                    }
                },
            )
            HorizontalDivider(Modifier.padding(horizontal = 16.dp))
            ListItem(
                headlineContent = { Text(stringResource(R.string.settings_decks_visibility)) },
                trailingContent = {
                    SingleChoiceSegmentedButtonRow {
                        SegmentedButton(
                            selected = s.decksVisibility == "friends",
                            onClick = { vm.setDecksVisibility("friends") },
                            shape = SegmentedButtonDefaults.itemShape(0, 2),
                            enabled = !s.saving,
                            label = { Text(stringResource(R.string.settings_friends)) },
                        )
                        SegmentedButton(
                            selected = s.decksVisibility == "private",
                            onClick = { vm.setDecksVisibility("private") },
                            shape = SegmentedButtonDefaults.itemShape(1, 2),
                            enabled = !s.saving,
                            label = { Text(stringResource(R.string.settings_private)) },
                        )
                    }
                },
            )
            HorizontalDivider(Modifier.padding(horizontal = 16.dp))
            ListItem(
                headlineContent = { Text(stringResource(R.string.settings_discoverable)) },
                trailingContent = {
                    Switch(
                        checked = s.discoverable,
                        onCheckedChange = { vm.setDiscoverable(it) },
                        enabled = !s.saving,
                    )
                },
            )

            SectionLabel(stringResource(R.string.settings_account_title))

            ListItem(
                headlineContent = { Text(stringResource(R.string.settings_logout)) },
                trailingContent = {
                    OutlinedButton(onClick = vm::logout) {
                        Text(stringResource(R.string.settings_logout))
                    }
                },
            )

            Spacer(Modifier.height(32.dp))
            Text(
                stringResource(R.string.settings_version, BuildConfig.VERSION_NAME),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(bottom = 16.dp),
            )
        }
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(
        text = text.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = MaterialTheme.colorScheme.primary,
        modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 20.dp, bottom = 4.dp),
    )
}

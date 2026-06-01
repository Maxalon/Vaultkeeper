package com.vaultkeeper.app.ui.home

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.R
import com.vaultkeeper.app.data.auth.Session
import org.koin.androidx.compose.koinViewModel

@Composable
fun HomeScreen(onOpenDeck: (Long) -> Unit = {}, vm: HomeViewModel = koinViewModel()) {
    val session by vm.session.collectAsStateWithLifecycle()
    val username = (session as? Session.Authenticated)?.user?.username ?: "—"

    Scaffold { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                stringResource(R.string.home_greeting, username),
                style = MaterialTheme.typography.headlineSmall,
            )
            Spacer(Modifier.height(24.dp))
            OutlinedButton(onClick = vm::logout) {
                Text(stringResource(R.string.home_logout))
            }
        }
    }
}

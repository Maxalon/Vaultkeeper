package com.vaultkeeper.app.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.vaultkeeper.app.ui.home.HomeViewModel
import org.koin.androidx.compose.koinViewModel

@Composable
fun CollectionScreen(vm: HomeViewModel = koinViewModel()) {
    Column(
        modifier = Modifier.fillMaxSize(),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("Collection")
        Spacer(Modifier.height(16.dp))
        TextButton(onClick = vm::logout) { Text("Log out") }
    }
}

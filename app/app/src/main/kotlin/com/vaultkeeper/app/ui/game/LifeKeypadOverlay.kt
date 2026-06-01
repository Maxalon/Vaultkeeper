package com.vaultkeeper.app.ui.game

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.BasicAlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LifeKeypadOverlay(
    currentLife: Int,
    onConfirm: (delta: Int) -> Unit,
    onDismiss: () -> Unit,
) {
    var positive by remember { mutableStateOf(true) }
    var digits by remember { mutableStateOf("") }

    val parsedAbsolute = digits.toIntOrNull() ?: 0
    val delta = if (positive) parsedAbsolute else -parsedAbsolute
    val newTotal = currentLife + delta
    val isValid = parsedAbsolute > 0

    BasicAlertDialog(onDismissRequest = onDismiss) {
        Surface(
            shape = MaterialTheme.shapes.large,
            tonalElevation = 6.dp,
            modifier = Modifier
                .fillMaxWidth()
                .imePadding(),
        ) {
            Column(
                modifier = Modifier.padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                Text(
                    text = "Adjust life",
                    style = MaterialTheme.typography.titleLarge,
                )

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (positive) {
                        Button(onClick = {}) { Text("+") }
                        OutlinedButton(onClick = { positive = false }) { Text("−") }
                    } else {
                        OutlinedButton(onClick = { positive = true }) { Text("+") }
                        Button(onClick = {}) { Text("−") }
                    }
                }

                OutlinedTextField(
                    value = digits,
                    onValueChange = { raw ->
                        val filtered = raw.filter { it.isDigit() }
                        digits = if (filtered.startsWith("0") && filtered.length > 1) {
                            filtered.trimStart('0')
                        } else {
                            filtered
                        }
                    },
                    label = { Text("Amount") },
                    prefix = { Text(if (positive) "+" else "−") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.fillMaxWidth(),
                )

                if (isValid) {
                    Text(
                        text = "$currentLife → $newTotal",
                        style = MaterialTheme.typography.headlineSmall,
                        textAlign = TextAlign.Center,
                        color = when {
                            delta > 0 -> MaterialTheme.colorScheme.primary
                            else -> MaterialTheme.colorScheme.error
                        },
                    )
                } else {
                    Spacer(Modifier.height(28.dp))
                }

                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.End,
                ) {
                    TextButton(onClick = onDismiss) { Text("Cancel") }
                    Spacer(Modifier.width(8.dp))
                    OutlinedButton(
                        onClick = { onConfirm(delta) },
                        enabled = isValid,
                    ) { Text("Confirm") }
                }
            }
        }
    }
}

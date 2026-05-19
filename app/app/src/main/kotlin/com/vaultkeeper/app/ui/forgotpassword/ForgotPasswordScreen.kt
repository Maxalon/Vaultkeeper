package com.vaultkeeper.app.ui.forgotpassword

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.R
import org.koin.androidx.compose.koinViewModel

@Composable
fun ForgotPasswordScreen(
    onNavigateToLogin: () -> Unit,
    vm: ForgotPasswordViewModel = koinViewModel(),
) {
    val s by vm.state.collectAsStateWithLifecycle()

    Scaffold { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(stringResource(R.string.forgot_password_title), style = MaterialTheme.typography.headlineMedium)
            Spacer(Modifier.height(8.dp))
            Text(
                stringResource(R.string.forgot_password_subtitle),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(24.dp))

            if (s.sent) {
                Text(
                    stringResource(R.string.forgot_password_confirmation),
                    style = MaterialTheme.typography.bodyLarge,
                    color = MaterialTheme.colorScheme.primary,
                )
                Spacer(Modifier.height(24.dp))
                TextButton(onClick = onNavigateToLogin) {
                    Text(stringResource(R.string.forgot_password_back_to_login))
                }
            } else {
                OutlinedTextField(
                    value = s.email,
                    onValueChange = vm::onEmailChange,
                    label = { Text(stringResource(R.string.forgot_password_email)) },
                    singleLine = true,
                    enabled = !s.submitting,
                    modifier = Modifier.fillMaxWidth(),
                )

                if (s.error != null) {
                    Spacer(Modifier.height(12.dp))
                    Text(s.error!!, color = MaterialTheme.colorScheme.error)
                }

                Spacer(Modifier.height(24.dp))

                Button(
                    onClick = vm::submit,
                    enabled = !s.submitting,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    if (s.submitting) {
                        CircularProgressIndicator(modifier = Modifier.height(20.dp))
                    } else {
                        Text(stringResource(R.string.forgot_password_submit))
                    }
                }

                Spacer(Modifier.height(8.dp))

                TextButton(onClick = onNavigateToLogin, enabled = !s.submitting) {
                    Text(stringResource(R.string.forgot_password_back_to_login))
                }
            }
        }
    }
}

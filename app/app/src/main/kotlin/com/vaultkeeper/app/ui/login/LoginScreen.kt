package com.vaultkeeper.app.ui.login

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
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.R
import androidx.compose.ui.res.stringResource
import org.koin.androidx.compose.koinViewModel

@Composable
fun LoginScreen(vm: LoginViewModel = koinViewModel()) {
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
            Text(stringResource(R.string.login_title), style = MaterialTheme.typography.headlineMedium)
            Spacer(Modifier.height(24.dp))

            OutlinedTextField(
                value = s.username,
                onValueChange = vm::onUsernameChange,
                label = { Text(stringResource(R.string.login_username)) },
                singleLine = true,
                enabled = !s.submitting,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = s.password,
                onValueChange = vm::onPasswordChange,
                label = { Text(stringResource(R.string.login_password)) },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
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
                enabled = !s.submitting && s.username.isNotBlank() && s.password.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (s.submitting) {
                    CircularProgressIndicator(modifier = Modifier.height(20.dp))
                } else {
                    Text(stringResource(R.string.login_submit))
                }
            }
        }
    }
}

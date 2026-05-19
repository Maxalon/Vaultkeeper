package com.vaultkeeper.app.ui.resetpassword

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.vaultkeeper.app.R
import org.koin.androidx.compose.koinViewModel

@Composable
fun ResetPasswordScreen(
    token: String,
    onNavigateToLogin: () -> Unit,
    vm: ResetPasswordViewModel = koinViewModel(),
) {
    val s by vm.state.collectAsStateWithLifecycle()

    Scaffold { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(24.dp)
                .verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(stringResource(R.string.reset_password_title), style = MaterialTheme.typography.headlineMedium)
            Spacer(Modifier.height(24.dp))

            OutlinedTextField(
                value = s.email,
                onValueChange = vm::onEmailChange,
                label = { Text(stringResource(R.string.reset_password_email)) },
                singleLine = true,
                enabled = !s.submitting,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = s.password,
                onValueChange = vm::onPasswordChange,
                label = { Text(stringResource(R.string.reset_password_new_password)) },
                singleLine = true,
                visualTransformation = PasswordVisualTransformation(),
                enabled = !s.submitting,
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = s.confirmPassword,
                onValueChange = vm::onConfirmPasswordChange,
                label = { Text(stringResource(R.string.reset_password_confirm_password)) },
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
                onClick = { vm.submit(token, onNavigateToLogin) },
                enabled = !s.submitting,
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (s.submitting) {
                    CircularProgressIndicator(modifier = Modifier.height(20.dp))
                } else {
                    Text(stringResource(R.string.reset_password_submit))
                }
            }
        }
    }
}

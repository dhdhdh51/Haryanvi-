package com.haryanvi.translator.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.haryanvi.translator.R
import com.haryanvi.translator.ui.AuthViewModel
import com.haryanvi.translator.ui.components.ErrorCard
import com.haryanvi.translator.ui.components.SectionCard

/**
 * Sign-in and registration.
 *
 * The server address comes first because this is a self-hosted backend: the
 * app has no idea where its API lives until the user says so.
 */
@Composable
fun AuthScreen(
    viewModel: AuthViewModel,
    onSignedIn: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val state by viewModel.state.collectAsState()
    var passwordVisible by remember { mutableStateOf(false) }

    LaunchedEffect(state.signedIn) {
        if (state.signedIn) onSignedIn()
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.height(28.dp))

        Surface(
            shape = RoundedCornerShape(20.dp),
            color = MaterialTheme.colorScheme.primaryContainer,
            modifier = Modifier.size(72.dp),
        ) {
            Box(contentAlignment = Alignment.Center) {
                Text(
                    text = "ह",
                    style = MaterialTheme.typography.displaySmall,
                    color = MaterialTheme.colorScheme.onPrimaryContainer,
                )
            }
        }

        Spacer(Modifier.height(20.dp))

        Text(
            text = state.serverName.ifBlank { stringResource(R.string.app_name) },
            style = MaterialTheme.typography.headlineSmall,
        )

        Spacer(Modifier.height(6.dp))

        Text(
            text = if (state.mode == AuthViewModel.Mode.SIGN_IN) {
                stringResource(R.string.auth_welcome_back)
            } else {
                stringResource(R.string.auth_welcome_new)
            },
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )

        Spacer(Modifier.height(28.dp))

        /* ---- Server address ------------------------------------------ */
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = stringResource(R.string.auth_server_label),
                    style = MaterialTheme.typography.labelLarge,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.weight(1f),
                )

                if (state.serverChecked && state.serverReachable) {
                    Icon(
                        imageVector = Icons.Default.CheckCircle,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.tertiary,
                        modifier = Modifier.size(18.dp),
                    )
                }
            }

            Spacer(Modifier.height(8.dp))

            OutlinedTextField(
                value = state.serverUrl,
                onValueChange = viewModel::onServerUrlChange,
                modifier = Modifier.fillMaxWidth(),
                placeholder = { Text(stringResource(R.string.auth_server_hint)) },
                singleLine = true,
                shape = RoundedCornerShape(12.dp),
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.Uri,
                    imeAction = ImeAction.Done,
                ),
                supportingText = { Text(stringResource(R.string.auth_server_help)) },
            )

            Spacer(Modifier.height(8.dp))

            TextButton(
                onClick = viewModel::saveServerAndCheck,
                enabled = !state.isBusy && state.serverUrl.isNotBlank(),
            ) {
                Text(
                    if (state.serverChecked && state.serverReachable) {
                        "Check again"
                    } else {
                        stringResource(R.string.action_continue)
                    }
                )
            }
        }

        Spacer(Modifier.height(14.dp))

        ErrorCard(message = state.error)

        if (state.error != null) Spacer(Modifier.height(14.dp))

        /* ---- Credentials --------------------------------------------- */
        SectionCard {
            if (state.mode == AuthViewModel.Mode.SIGN_UP) {
                OutlinedTextField(
                    value = state.displayName,
                    onValueChange = viewModel::onDisplayNameChange,
                    modifier = Modifier.fillMaxWidth(),
                    label = { Text(stringResource(R.string.auth_display_name)) },
                    singleLine = true,
                    shape = RoundedCornerShape(12.dp),
                    isError = state.fieldErrors.containsKey("display_name"),
                    supportingText = state.fieldErrors["display_name"]?.let { { Text(it) } },
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Next),
                )

                Spacer(Modifier.height(12.dp))
            }

            OutlinedTextField(
                value = state.email,
                onValueChange = viewModel::onEmailChange,
                modifier = Modifier.fillMaxWidth(),
                label = { Text(stringResource(R.string.auth_email)) },
                singleLine = true,
                shape = RoundedCornerShape(12.dp),
                isError = state.fieldErrors.containsKey("email"),
                supportingText = state.fieldErrors["email"]?.let { { Text(it) } },
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.Email,
                    imeAction = ImeAction.Next,
                ),
            )

            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = state.password,
                onValueChange = viewModel::onPasswordChange,
                modifier = Modifier.fillMaxWidth(),
                label = { Text(stringResource(R.string.auth_password)) },
                singleLine = true,
                shape = RoundedCornerShape(12.dp),
                isError = state.fieldErrors.containsKey("password"),
                supportingText = {
                    Text(
                        state.fieldErrors["password"]
                            ?: if (state.mode == AuthViewModel.Mode.SIGN_UP) {
                                stringResource(R.string.auth_password_hint)
                            } else {
                                ""
                            }
                    )
                },
                visualTransformation = if (passwordVisible) {
                    VisualTransformation.None
                } else {
                    PasswordVisualTransformation()
                },
                keyboardOptions = KeyboardOptions(
                    keyboardType = KeyboardType.Password,
                    imeAction = ImeAction.Done,
                ),
                trailingIcon = {
                    IconButton(onClick = { passwordVisible = !passwordVisible }) {
                        Icon(
                            imageVector = if (passwordVisible) {
                                Icons.Default.VisibilityOff
                            } else {
                                Icons.Default.Visibility
                            },
                            contentDescription = null,
                        )
                    }
                },
            )

            Spacer(Modifier.height(18.dp))

            Button(
                onClick = viewModel::submit,
                enabled = state.canSubmit,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(52.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                if (state.isBusy) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                        color = MaterialTheme.colorScheme.onPrimary,
                    )
                    Spacer(Modifier.width(10.dp))
                    Text(
                        if (state.mode == AuthViewModel.Mode.SIGN_IN) {
                            stringResource(R.string.auth_signing_in)
                        } else {
                            stringResource(R.string.auth_creating)
                        }
                    )
                } else {
                    Text(
                        text = if (state.mode == AuthViewModel.Mode.SIGN_IN) {
                            stringResource(R.string.auth_sign_in)
                        } else {
                            stringResource(R.string.auth_sign_up)
                        },
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }

        Spacer(Modifier.height(10.dp))

        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.Center,
        ) {
            if (state.mode == AuthViewModel.Mode.SIGN_IN) {
                if (state.registrationOpen) {
                    TextButton(onClick = { viewModel.setMode(AuthViewModel.Mode.SIGN_UP) }) {
                        Text(stringResource(R.string.auth_need_account))
                    }
                }
            } else {
                TextButton(onClick = { viewModel.setMode(AuthViewModel.Mode.SIGN_IN) }) {
                    Text(stringResource(R.string.auth_have_account))
                }
            }
        }

        Spacer(Modifier.height(32.dp))
    }
}

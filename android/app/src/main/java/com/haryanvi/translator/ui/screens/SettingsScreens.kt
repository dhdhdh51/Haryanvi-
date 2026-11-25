package com.haryanvi.translator.ui.screens

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.provider.Settings
import android.view.inputmethod.InputMethodManager
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.haryanvi.translator.BuildConfig
import com.haryanvi.translator.R
import com.haryanvi.translator.keyboard.HaryanviInputMethodService
import com.haryanvi.translator.ui.SettingsViewModel
import com.haryanvi.translator.ui.components.ErrorCard
import com.haryanvi.translator.ui.components.SectionCard
import com.haryanvi.translator.ui.components.SectionHeading
import com.haryanvi.translator.ui.components.SettingSwitch

/* =====================================================================
 | Settings hub
 * =================================================================== */

@Composable
fun SettingsScreen(
    viewModel: SettingsViewModel,
    onOpenDialects: () -> Unit,
    onOpenKeyboard: () -> Unit,
    onOpenFloating: () -> Unit,
    onOpenAccount: () -> Unit,
    onOpenPrivacy: () -> Unit,
    onOpenAbout: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val prefs by viewModel.preferences.collectAsState()
    val usage by viewModel.usage.collectAsState()
    val account by viewModel.account.collectAsState()
    val state by viewModel.state.collectAsState()

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        ErrorCard(message = state.error, onDismiss = viewModel::consumeMessage)

        /* ---- Usage summary ------------------------------------------- */
        SectionCard {
            Text(
                text = stringResource(R.string.account_usage),
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(10.dp))

            Row(verticalAlignment = Alignment.Bottom) {
                Text(
                    text = usage.dailyUsed.toString(),
                    style = MaterialTheme.typography.displaySmall,
                    color = MaterialTheme.colorScheme.primary,
                )
                Spacer(Modifier.width(8.dp))
                Text(
                    text = if (usage.dailyUnlimited) {
                        stringResource(R.string.account_usage_unlimited)
                    } else {
                        "/ ${usage.dailyLimit} ${stringResource(R.string.account_usage_today).lowercase()}"
                    },
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.padding(bottom = 4.dp),
                )
            }

            if (!usage.dailyUnlimited && usage.dailyLimit > 0) {
                Spacer(Modifier.height(8.dp))
                LinearProgressIndicator(
                    progress = { usage.dailyFraction },
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(6.dp),
                )
            }

            Spacer(Modifier.height(8.dp))

            Text(
                text = "${stringResource(R.string.account_usage_month)}: ${usage.monthlyUsed}" +
                    if (usage.monthlyUnlimited) "" else " / ${usage.monthlyLimit}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        /* ---- Translation -------------------------------------------- */
        SectionHeading(stringResource(R.string.translate_title))

        SectionCard(contentPadding = 6) {
            NavigationRow(
                title = stringResource(R.string.dialect_title),
                subtitle = viewModel.dialects.collectAsState().value
                    .firstOrNull { it.code == prefs.dialect }?.nameEn ?: prefs.dialect,
                onClick = onOpenDialects,
            )

            SettingSwitch(
                title = stringResource(R.string.translate_show_roman),
                description = "Also produce a Latin-script version of each translation.",
                checked = prefs.includeRoman,
                onCheckedChange = viewModel::setIncludeRoman,
                modifier = Modifier.padding(horizontal = 12.dp),
            )
        }

        /* ---- Input surfaces ----------------------------------------- */
        SectionHeading("Typing")

        SectionCard(contentPadding = 6) {
            NavigationRow(
                title = stringResource(R.string.keyboard_name),
                subtitle = stringResource(R.string.keyboard_settings_title),
                onClick = onOpenKeyboard,
            )
            NavigationRow(
                title = stringResource(R.string.floating_title),
                subtitle = if (prefs.floatingEnabled) "On" else "Off",
                onClick = onOpenFloating,
            )

            SettingSwitch(
                title = stringResource(R.string.keyboard_haptics),
                description = null,
                checked = prefs.keyboardHaptics,
                onCheckedChange = viewModel::setKeyboardHaptics,
                modifier = Modifier.padding(horizontal = 12.dp),
            )
        }

        /* ---- Account ------------------------------------------------- */
        SectionHeading(stringResource(R.string.account_title))

        SectionCard(contentPadding = 6) {
            NavigationRow(
                title = account?.displayName ?: stringResource(R.string.account_title),
                subtitle = account?.email ?: "",
                onClick = onOpenAccount,
            )
            NavigationRow(
                title = stringResource(R.string.privacy_title),
                subtitle = if (prefs.saveHistory) "History on" else "History off",
                onClick = onOpenPrivacy,
            )
            NavigationRow(
                title = stringResource(R.string.about_title),
                subtitle = stringResource(R.string.about_version, BuildConfig.VERSION_NAME),
                onClick = onOpenAbout,
            )
        }

        Spacer(Modifier.height(28.dp))
    }
}

@Composable
private fun NavigationRow(
    title: String,
    subtitle: String,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.bodyLarge)

            if (subtitle.isNotBlank()) {
                Text(
                    text = subtitle,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        Icon(
            imageVector = Icons.AutoMirrored.Filled.KeyboardArrowRight,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

/* =====================================================================
 | Dialect and tone selection
 * =================================================================== */

@Composable
fun DialectScreen(
    viewModel: SettingsViewModel,
    modifier: Modifier = Modifier,
) {
    val dialects by viewModel.dialects.collectAsState()
    val tones by viewModel.tones.collectAsState()
    val prefs by viewModel.preferences.collectAsState()

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        Text(
            text = stringResource(R.string.dialect_subtitle),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        Spacer(Modifier.height(12.dp))

        dialects.forEach { dialect ->
            SelectableCard(
                title = dialect.nameEn,
                localTitle = dialect.nameHi,
                description = dialect.description.ifBlank { dialect.regionLabel },
                selected = dialect.code == prefs.dialect,
                onClick = { viewModel.setDialect(dialect.code) },
            )
            Spacer(Modifier.height(8.dp))
        }

        Spacer(Modifier.height(12.dp))

        Text(
            text = stringResource(R.string.tone_subtitle),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        Spacer(Modifier.height(12.dp))

        tones.forEach { tone ->
            SelectableCard(
                title = tone.nameEn,
                localTitle = tone.nameHi,
                description = tone.description,
                selected = tone.code == prefs.tone,
                onClick = { viewModel.setTone(tone.code) },
            )
            Spacer(Modifier.height(8.dp))
        }

        Spacer(Modifier.height(16.dp))

        SectionCard {
            Text(
                text = stringResource(R.string.translate_from),
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(8.dp))

            listOf(
                "auto" to stringResource(R.string.lang_auto),
                "hi" to stringResource(R.string.lang_hindi),
                "en" to stringResource(R.string.lang_english),
            ).forEach { (code, label) ->
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable { viewModel.setDefaultSourceLang(code) }
                        .padding(vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        imageVector = if (prefs.defaultSourceLang == code) {
                            Icons.Default.CheckCircle
                        } else {
                            Icons.Default.RadioButtonUnchecked
                        },
                        contentDescription = null,
                        tint = if (prefs.defaultSourceLang == code) {
                            MaterialTheme.colorScheme.primary
                        } else {
                            MaterialTheme.colorScheme.outline
                        },
                        modifier = Modifier.size(20.dp),
                    )
                    Spacer(Modifier.width(12.dp))
                    Text(label, style = MaterialTheme.typography.bodyLarge)
                }
            }
        }

        Spacer(Modifier.height(28.dp))
    }
}

@Composable
private fun SelectableCard(
    title: String,
    localTitle: String,
    description: String,
    selected: Boolean,
    onClick: () -> Unit,
) {
    Card(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (selected) {
                MaterialTheme.colorScheme.primaryContainer
            } else {
                MaterialTheme.colorScheme.surface
            },
        ),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        text = title,
                        style = MaterialTheme.typography.titleMedium,
                        color = if (selected) {
                            MaterialTheme.colorScheme.onPrimaryContainer
                        } else {
                            MaterialTheme.colorScheme.onSurface
                        },
                    )

                    if (localTitle.isNotBlank()) {
                        Spacer(Modifier.width(8.dp))
                        Text(
                            text = localTitle,
                            style = MaterialTheme.typography.bodyMedium,
                            color = if (selected) {
                                MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.75f)
                            } else {
                                MaterialTheme.colorScheme.onSurfaceVariant
                            },
                        )
                    }
                }

                if (description.isNotBlank()) {
                    Spacer(Modifier.height(4.dp))
                    Text(
                        text = description,
                        style = MaterialTheme.typography.bodySmall,
                        color = if (selected) {
                            MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.8f)
                        } else {
                            MaterialTheme.colorScheme.onSurfaceVariant
                        },
                    )
                }
            }

            if (selected) {
                Icon(
                    imageVector = Icons.Default.CheckCircle,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.primary,
                )
            }
        }
    }
}

/* =====================================================================
 | Keyboard setup
 * =================================================================== */

@Composable
fun KeyboardSetupScreen(modifier: Modifier = Modifier) {
    val context = LocalContext.current

    // Re-read on every recomposition so returning from system settings
    // reflects the new state.
    val enabled = remember(context) { mutableStateOf(isKeyboardEnabled(context)) }
    val selected = remember(context) { mutableStateOf(isKeyboardSelected(context)) }

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        SectionCard {
            Text(
                text = stringResource(R.string.keyboard_setup_title),
                style = MaterialTheme.typography.titleLarge,
            )

            Spacer(Modifier.height(8.dp))

            Text(
                text = "Two steps, both handled by Android itself. Haryanvi AI cannot enable a " +
                    "keyboard on your behalf.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(Modifier.height(12.dp))

        StepCard(
            index = 1,
            title = stringResource(R.string.keyboard_step_enable),
            done = enabled.value,
            actionLabel = stringResource(R.string.keyboard_step_enable_action),
            onAction = {
                context.startActivity(
                    Intent(Settings.ACTION_INPUT_METHOD_SETTINGS)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                )
                enabled.value = isKeyboardEnabled(context)
            },
        )

        Spacer(Modifier.height(10.dp))

        StepCard(
            index = 2,
            title = stringResource(R.string.keyboard_step_select),
            done = selected.value,
            actionLabel = stringResource(R.string.keyboard_step_select_action),
            onAction = {
                (context.getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager)
                    ?.showInputMethodPicker()
                selected.value = isKeyboardSelected(context)
            },
        )

        Spacer(Modifier.height(16.dp))

        SectionCard {
            Text(
                text = stringResource(R.string.privacy_what_we_do),
                style = MaterialTheme.typography.titleMedium,
            )

            Spacer(Modifier.height(8.dp))

            Text(
                text = "The keyboard never sends anything until you press the हरियाणवी key. " +
                    "It does not record keystrokes, and it refuses to translate inside password, " +
                    "PIN, email and other private fields — the translate key is disabled there.",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(Modifier.height(28.dp))
    }
}

@Composable
private fun StepCard(
    index: Int,
    title: String,
    done: Boolean,
    actionLabel: String,
    onAction: () -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(
            containerColor = if (done) {
                MaterialTheme.colorScheme.surfaceVariant
            } else {
                MaterialTheme.colorScheme.surface
            },
        ),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Row(
            modifier = Modifier.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier.size(28.dp),
                contentAlignment = Alignment.Center,
            ) {
                if (done) {
                    Icon(
                        Icons.Default.CheckCircle,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.tertiary,
                    )
                } else {
                    Text(
                        text = index.toString(),
                        style = MaterialTheme.typography.titleMedium,
                        color = MaterialTheme.colorScheme.primary,
                    )
                }
            }

            Spacer(Modifier.width(14.dp))

            Column(Modifier.weight(1f)) {
                Text(title, style = MaterialTheme.typography.bodyLarge)

                Spacer(Modifier.height(6.dp))

                OutlinedButton(onClick = onAction, shape = RoundedCornerShape(10.dp)) {
                    Text(actionLabel)
                }
            }
        }
    }
}

private fun isKeyboardEnabled(context: Context): Boolean {
    val manager = context.getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager
        ?: return false

    return manager.enabledInputMethodList.any {
        it.packageName == context.packageName
    }
}

private fun isKeyboardSelected(context: Context): Boolean {
    val current = Settings.Secure.getString(
        context.contentResolver,
        Settings.Secure.DEFAULT_INPUT_METHOD,
    ).orEmpty()

    return current.contains(HaryanviInputMethodService::class.java.name) ||
        current.startsWith(context.packageName)
}

/* =====================================================================
 | Floating translator settings
 * =================================================================== */

@Composable
fun FloatingSettingsScreen(
    viewModel: SettingsViewModel,
    onStartBubble: () -> Unit,
    onStopBubble: () -> Unit,
    hasOverlayPermission: Boolean,
    onRequestPermission: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val prefs by viewModel.preferences.collectAsState()

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        SectionCard {
            Text(
                text = stringResource(R.string.floating_title),
                style = MaterialTheme.typography.titleLarge,
            )

            Spacer(Modifier.height(6.dp))

            Text(
                text = stringResource(R.string.floating_subtitle),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(Modifier.height(12.dp))

        /* ---- The permission, explained before it is asked for -------- */
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = stringResource(R.string.floating_permission_title),
                    style = MaterialTheme.typography.titleMedium,
                    modifier = Modifier.weight(1f),
                )

                if (hasOverlayPermission) {
                    Icon(
                        Icons.Default.CheckCircle,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.tertiary,
                    )
                }
            }

            Spacer(Modifier.height(8.dp))

            Text(
                text = stringResource(R.string.floating_permission_body),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(14.dp))

            if (hasOverlayPermission) {
                Text(
                    text = stringResource(R.string.floating_permission_granted),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.tertiary,
                )
            } else {
                Button(onClick = onRequestPermission, shape = RoundedCornerShape(12.dp)) {
                    Text(stringResource(R.string.floating_permission_grant))
                }
            }
        }

        Spacer(Modifier.height(12.dp))

        SectionCard {
            SettingSwitch(
                title = stringResource(R.string.floating_enable),
                description = stringResource(R.string.floating_clipboard_note),
                checked = prefs.floatingEnabled && hasOverlayPermission,
                enabled = hasOverlayPermission,
                onCheckedChange = { enabled ->
                    viewModel.setFloatingEnabled(enabled)
                    if (enabled) onStartBubble() else onStopBubble()
                },
            )
        }

        Spacer(Modifier.height(28.dp))
    }
}

/* =====================================================================
 | Privacy
 * =================================================================== */

@Composable
fun PrivacyScreen(
    viewModel: SettingsViewModel,
    modifier: Modifier = Modifier,
) {
    val prefs by viewModel.preferences.collectAsState()
    val appInfo by viewModel.appInfo.collectAsState()
    val state by viewModel.state.collectAsState()

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        if (state.message != null) {
            SectionCard {
                Text(state.message.orEmpty(), style = MaterialTheme.typography.bodyMedium)
                TextButton(onClick = viewModel::consumeMessage) {
                    Text(stringResource(R.string.action_done))
                }
            }
            Spacer(Modifier.height(12.dp))
        }

        ErrorCard(message = state.error, onDismiss = viewModel::consumeMessage)

        SectionCard {
            SettingSwitch(
                title = stringResource(R.string.privacy_save_history),
                description = stringResource(R.string.privacy_save_history_help),
                checked = prefs.saveHistory,
                onCheckedChange = viewModel::setSaveHistory,
                enabled = !state.isBusy,
            )

            SettingSwitch(
                title = stringResource(R.string.privacy_cloud_sync),
                description = stringResource(R.string.privacy_cloud_sync_help),
                checked = prefs.cloudSync,
                onCheckedChange = viewModel::setCloudSync,
                enabled = prefs.saveHistory && !state.isBusy,
            )
        }

        Spacer(Modifier.height(16.dp))

        SectionCard {
            Text(
                text = stringResource(R.string.privacy_what_we_do),
                style = MaterialTheme.typography.titleMedium,
            )

            Spacer(Modifier.height(10.dp))

            Text(
                text = stringResource(R.string.privacy_promises).trim(),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        if (appInfo.privacyPolicy.isNotBlank()) {
            Spacer(Modifier.height(16.dp))

            SectionCard {
                Text(
                    text = stringResource(R.string.privacy_policy_title),
                    style = MaterialTheme.typography.titleMedium,
                )

                Spacer(Modifier.height(10.dp))

                Text(
                    text = appInfo.privacyPolicy,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        Spacer(Modifier.height(28.dp))
    }
}

/* =====================================================================
 | Account
 * =================================================================== */

@Composable
fun AccountScreen(
    viewModel: SettingsViewModel,
    onSignedOut: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val account by viewModel.account.collectAsState()
    val usage by viewModel.usage.collectAsState()
    val state by viewModel.state.collectAsState()

    var showPasswordChange by remember { mutableStateOf(false) }
    var showDelete by remember { mutableStateOf(false) }

    // Navigation is a side effect, so it must not run during composition.
    LaunchedEffect(state.signedOut, state.accountDeleted) {
        if (state.signedOut || state.accountDeleted) {
            onSignedOut()
        }
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        ErrorCard(message = state.error, onDismiss = viewModel::consumeMessage)

        SectionCard {
            Text(
                text = account?.displayName.orEmpty().ifBlank { stringResource(R.string.account_title) },
                style = MaterialTheme.typography.titleLarge,
            )
            Text(
                text = account?.email.orEmpty(),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(Modifier.height(12.dp))

        SectionCard {
            Text(
                text = stringResource(R.string.account_usage),
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(10.dp))

            UsageRow(
                label = stringResource(R.string.account_usage_today),
                used = usage.dailyUsed,
                limit = usage.dailyLimit,
                unlimited = usage.dailyUnlimited,
                fraction = usage.dailyFraction,
            )

            Spacer(Modifier.height(12.dp))

            UsageRow(
                label = stringResource(R.string.account_usage_month),
                used = usage.monthlyUsed,
                limit = usage.monthlyLimit,
                unlimited = usage.monthlyUnlimited,
                fraction = usage.monthlyFraction,
            )

            Spacer(Modifier.height(12.dp))

            Text(
                text = "All time: ${usage.allTime}",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(Modifier.height(16.dp))

        SectionCard {
            TextButton(
                onClick = { showPasswordChange = true },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.account_change_password))
            }

            TextButton(
                onClick = { viewModel.signOut(allDevices = false) },
                modifier = Modifier.fillMaxWidth(),
                enabled = !state.isBusy,
            ) {
                Text(stringResource(R.string.account_sign_out))
            }

            TextButton(
                onClick = { viewModel.signOut(allDevices = true) },
                modifier = Modifier.fillMaxWidth(),
                enabled = !state.isBusy,
            ) {
                Text(stringResource(R.string.account_sign_out_all))
            }

            TextButton(
                onClick = { showDelete = true },
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(
                    text = stringResource(R.string.account_delete),
                    color = MaterialTheme.colorScheme.error,
                )
            }
        }

        Spacer(Modifier.height(28.dp))
    }

    if (showPasswordChange) {
        PasswordChangeDialog(
            onDismiss = { showPasswordChange = false },
            onConfirm = { current, next ->
                viewModel.changePassword(current, next)
                showPasswordChange = false
            },
        )
    }

    if (showDelete) {
        DeleteAccountDialog(
            onDismiss = { showDelete = false },
            onConfirm = { password ->
                viewModel.deleteAccount(password)
                showDelete = false
            },
        )
    }
}

@Composable
private fun UsageRow(
    label: String,
    used: Int,
    limit: Int,
    unlimited: Boolean,
    fraction: Float,
) {
    Column {
        Row {
            Text(label, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
            Text(
                text = if (unlimited) {
                    stringResource(R.string.account_usage_unlimited)
                } else {
                    "$used / $limit"
                },
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        if (!unlimited && limit > 0) {
            Spacer(Modifier.height(6.dp))
            LinearProgressIndicator(
                progress = { fraction },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(5.dp),
            )
        }
    }
}

@Composable
private fun PasswordChangeDialog(
    onDismiss: () -> Unit,
    onConfirm: (String, String) -> Unit,
) {
    var current by remember { mutableStateOf("") }
    var next by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.account_change_password)) },
        text = {
            Column {
                OutlinedTextField(
                    value = current,
                    onValueChange = { current = it },
                    label = { Text(stringResource(R.string.account_current_password)) },
                    visualTransformation = PasswordVisualTransformation(),
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(
                    value = next,
                    onValueChange = { next = it },
                    label = { Text(stringResource(R.string.account_new_password)) },
                    visualTransformation = PasswordVisualTransformation(),
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                    supportingText = { Text(stringResource(R.string.auth_password_hint)) },
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onConfirm(current, next) },
                enabled = current.isNotBlank() && next.length >= 8,
            ) {
                Text(stringResource(R.string.action_save))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) }
        },
    )
}

@Composable
private fun DeleteAccountDialog(
    onDismiss: () -> Unit,
    onConfirm: (String) -> Unit,
) {
    var password by remember { mutableStateOf("") }
    var confirmWord by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.account_delete_confirm_title)) },
        text = {
            Column {
                Text(
                    text = stringResource(R.string.account_delete_confirm_body),
                    style = MaterialTheme.typography.bodyMedium,
                )
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text(stringResource(R.string.auth_password)) },
                    visualTransformation = PasswordVisualTransformation(),
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(
                    value = confirmWord,
                    onValueChange = { confirmWord = it },
                    label = { Text(stringResource(R.string.account_delete_type)) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            TextButton(
                onClick = { onConfirm(password) },
                enabled = password.isNotBlank() && confirmWord.trim().uppercase() == "DELETE",
            ) {
                Text(
                    text = stringResource(R.string.account_delete),
                    color = MaterialTheme.colorScheme.error,
                )
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text(stringResource(R.string.action_cancel)) }
        },
    )
}

/* =====================================================================
 | About & support
 * =================================================================== */

@Composable
fun AboutScreen(
    viewModel: SettingsViewModel,
    modifier: Modifier = Modifier,
) {
    val appInfo by viewModel.appInfo.collectAsState()
    val serverUrl by viewModel.baseUrl.collectAsState()
    val context = LocalContext.current

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        SectionCard {
            Text(appInfo.appName, style = MaterialTheme.typography.titleLarge)
            Text(
                text = stringResource(R.string.about_version, BuildConfig.VERSION_NAME),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(10.dp))

            Text(
                text = stringResource(R.string.about_credits),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(Modifier.height(12.dp))

        SectionCard {
            Text(
                text = stringResource(R.string.about_server),
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.height(4.dp))
            Text(serverUrl, style = MaterialTheme.typography.bodyMedium)

            if (appInfo.supportEmail.isNotBlank()) {
                Spacer(Modifier.height(14.dp))
                OutlinedButton(
                    onClick = {
                        context.startActivity(
                            Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:${appInfo.supportEmail}"))
                        )
                    },
                    shape = RoundedCornerShape(12.dp),
                ) {
                    Text(stringResource(R.string.about_support))
                }
            }
        }

        if (appInfo.terms.isNotBlank()) {
            Spacer(Modifier.height(12.dp))

            SectionCard {
                Text(
                    text = stringResource(R.string.about_terms),
                    style = MaterialTheme.typography.titleMedium,
                )
                Spacer(Modifier.height(10.dp))
                Text(
                    text = appInfo.terms,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        Spacer(Modifier.height(28.dp))
    }
}



package com.haryanvi.translator.ui.screens

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.SwapHoriz
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.FlagCircle
import androidx.compose.material.icons.outlined.Translate
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.haryanvi.translator.R
import com.haryanvi.translator.domain.model.TranslationChannel
import com.haryanvi.translator.ui.TranslatorViewModel
import com.haryanvi.translator.ui.components.ChoiceChipRow
import com.haryanvi.translator.ui.components.EmptyState
import com.haryanvi.translator.ui.components.ErrorCard
import com.haryanvi.translator.ui.components.SectionCard
import com.haryanvi.translator.ui.components.TypingDots
import com.haryanvi.translator.ui.theme.devanagariDisplay

/**
 * The main translation dashboard: source language, input, translate, result
 * card with copy / share / use-as-input / report.
 */
@Composable
fun TranslateScreen(
    viewModel: TranslatorViewModel,
    channel: TranslationChannel = TranslationChannel.APP,
    onOpenDialects: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val state by viewModel.state.collectAsState()
    val dialects by viewModel.dialects.collectAsState()
    val tones by viewModel.tones.collectAsState()
    val context = LocalContext.current

    var showFeedback by remember { mutableStateOf(false) }

    Column(
        modifier = modifier
            .fillMaxSize()
            .verticalScroll(rememberScrollState())
            .padding(16.dp),
    ) {
        /* ---- Language row -------------------------------------------- */
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(
                        text = stringResource(R.string.translate_from),
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(6.dp))
                    ChoiceChipRow(
                        items = SOURCE_LANGUAGES,
                        selected = { it.first == state.sourceLang },
                        label = { stringResource(it.second) },
                        onSelect = { viewModel.setSourceLang(it.first) },
                        key = { it.first },
                    )
                }

                Spacer(Modifier.width(12.dp))

                Icon(
                    imageVector = Icons.Default.SwapHoriz,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.width(12.dp))

                Column(horizontalAlignment = Alignment.End) {
                    Text(
                        text = stringResource(R.string.translate_to),
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Spacer(Modifier.height(6.dp))
                    Text(
                        text = "हरियाणवी",
                        style = MaterialTheme.typography.titleMedium,
                        color = MaterialTheme.colorScheme.primary,
                    )
                }
            }
        }

        Spacer(Modifier.height(12.dp))

        /* ---- Dialect and tone --------------------------------------- */
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = stringResource(R.string.dialect_title),
                    style = MaterialTheme.typography.labelMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.weight(1f),
                )
                TextButton(onClick = onOpenDialects) {
                    Text(stringResource(R.string.action_settings))
                }
            }

            ChoiceChipRow(
                items = dialects,
                selected = { it.code == state.dialect },
                label = { it.nameEn },
                onSelect = { viewModel.setDialect(it.code) },
                key = { it.code },
            )

            Spacer(Modifier.height(14.dp))

            Text(
                text = stringResource(R.string.tone_title),
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(6.dp))

            ChoiceChipRow(
                items = tones,
                selected = { it.code == state.tone },
                label = { it.nameEn },
                onSelect = { viewModel.setTone(it.code) },
                key = { it.code },
            )
        }

        Spacer(Modifier.height(12.dp))

        /* ---- Input --------------------------------------------------- */
        SectionCard {
            OutlinedTextField(
                value = state.input,
                onValueChange = viewModel::onInputChange,
                modifier = Modifier
                    .fillMaxWidth()
                    .heightIn(min = 140.dp),
                placeholder = { Text(stringResource(R.string.translate_input_hint)) },
                isError = state.isOverLimit,
                shape = RoundedCornerShape(14.dp),
                textStyle = MaterialTheme.typography.bodyLarge,
            )

            Spacer(Modifier.height(8.dp))

            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = stringResource(
                        R.string.translate_char_counter,
                        state.charCount,
                        state.maxChars,
                    ),
                    style = MaterialTheme.typography.labelMedium,
                    color = if (state.isOverLimit) {
                        MaterialTheme.colorScheme.error
                    } else {
                        MaterialTheme.colorScheme.onSurfaceVariant
                    },
                    modifier = Modifier.weight(1f),
                )

                if (state.input.isNotEmpty()) {
                    TextButton(onClick = viewModel::clear) {
                        Icon(
                            Icons.Outlined.Close,
                            contentDescription = null,
                            modifier = Modifier.size(16.dp),
                        )
                        Spacer(Modifier.width(4.dp))
                        Text(stringResource(R.string.translate_clear))
                    }
                }
            }

            Spacer(Modifier.height(4.dp))

            Button(
                onClick = { viewModel.translate(channel) },
                enabled = state.canTranslate,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(52.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                if (state.isTranslating) {
                    TypingDots(dotSize = 7)
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.translate_working))
                } else {
                    Icon(Icons.Outlined.Translate, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text(
                        text = stringResource(R.string.translate_action),
                        style = MaterialTheme.typography.titleMedium,
                    )
                }
            }

            if (state.isTranslating) {
                Spacer(Modifier.height(10.dp))
                LinearProgressIndicator(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(3.dp),
                )
            }
        }

        Spacer(Modifier.height(12.dp))

        /* ---- Errors -------------------------------------------------- */
        ErrorCard(
            message = state.error,
            onRetry = if (state.providerMissing || state.quotaBlocked) {
                null
            } else {
                { viewModel.retry(channel) }
            },
            onDismiss = viewModel::dismissError,
        )

        if (state.error != null) {
            Spacer(Modifier.height(12.dp))
        }

        /* ---- Result -------------------------------------------------- */
        AnimatedVisibility(
            visible = state.result != null,
            enter = fadeIn() + slideInVertically { it / 6 },
            exit = fadeOut(),
        ) {
            val result = state.result

            if (result != null) {
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(18.dp),
                    colors = CardDefaults.cardColors(
                        containerColor = MaterialTheme.colorScheme.primaryContainer,
                    ),
                ) {
                    Column(Modifier.padding(18.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                text = stringResource(R.string.translate_result_title),
                                style = MaterialTheme.typography.labelMedium,
                                color = MaterialTheme.colorScheme.onPrimaryContainer,
                                modifier = Modifier.weight(1f),
                            )

                            if (!result.romanText.isNullOrBlank()) {
                                TextButton(onClick = viewModel::toggleScript) {
                                    Text(
                                        text = if (state.preferRoman) {
                                            stringResource(R.string.translate_show_devanagari)
                                        } else {
                                            stringResource(R.string.translate_show_roman)
                                        }
                                    )
                                }
                            }
                        }

                        Spacer(Modifier.height(8.dp))

                        SelectionContainer {
                            Text(
                                text = result.displayText(state.preferRoman),
                                style = devanagariDisplay,
                                color = MaterialTheme.colorScheme.onPrimaryContainer,
                            )
                        }

                        Spacer(Modifier.height(10.dp))

                        Text(
                            text = "${result.dialectName} · ${viewModel.tones.value.firstOrNull { it.code == result.toneCode }?.nameEn ?: result.toneCode}",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.7f),
                        )

                        Spacer(Modifier.height(14.dp))

                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(
                                onClick = {
                                    copyToClipboard(context, result.displayText(state.preferRoman))
                                },
                                modifier = Modifier.weight(1f),
                                shape = RoundedCornerShape(12.dp),
                                colors = ButtonDefaults.buttonColors(
                                    containerColor = MaterialTheme.colorScheme.primary,
                                ),
                            ) {
                                Icon(
                                    Icons.Default.ContentCopy,
                                    contentDescription = null,
                                    modifier = Modifier.size(18.dp),
                                )
                                Spacer(Modifier.width(6.dp))
                                Text(stringResource(R.string.translate_copy))
                            }

                            Button(
                                onClick = {
                                    shareText(context, result.displayText(state.preferRoman))
                                },
                                modifier = Modifier.weight(1f),
                                shape = RoundedCornerShape(12.dp),
                                colors = ButtonDefaults.buttonColors(
                                    containerColor = MaterialTheme.colorScheme.secondaryContainer,
                                    contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
                                ),
                            ) {
                                Icon(
                                    Icons.Default.Share,
                                    contentDescription = null,
                                    modifier = Modifier.size(18.dp),
                                )
                                Spacer(Modifier.width(6.dp))
                                Text(stringResource(R.string.translate_share))
                            }
                        }

                        Spacer(Modifier.height(6.dp))

                        Row {
                            TextButton(
                                onClick = viewModel::useResultAsInput,
                                modifier = Modifier.weight(1f),
                            ) {
                                Text(stringResource(R.string.translate_replace))
                            }

                            TextButton(
                                onClick = { showFeedback = true },
                                modifier = Modifier.weight(1f),
                            ) {
                                Icon(
                                    Icons.Outlined.FlagCircle,
                                    contentDescription = null,
                                    modifier = Modifier.size(16.dp),
                                )
                                Spacer(Modifier.width(4.dp))
                                Text(stringResource(R.string.translate_report))
                            }
                        }

                        if (state.feedbackSent) {
                            Spacer(Modifier.height(6.dp))
                            Text(
                                text = stringResource(R.string.feedback_thanks),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onPrimaryContainer,
                            )
                        }
                    }
                }
            }
        }

        /* ---- Empty state --------------------------------------------- */
        if (state.result == null && !state.isTranslating && state.error == null) {
            EmptyState(
                title = stringResource(R.string.translate_empty_title),
                body = stringResource(R.string.translate_empty_body),
                mark = "ह",
            )
        }

        Spacer(Modifier.height(24.dp))
    }

    if (showFeedback) {
        FeedbackSheet(
            onDismiss = { showFeedback = false },
            onSubmit = { reason, suggestion, comment ->
                viewModel.sendFeedback(reason, suggestion, comment)
                showFeedback = false
            },
        )
    }
}

/** Compact variant used by the share-to-translate sheet. */
@Composable
fun ShareTranslatePanel(
    viewModel: TranslatorViewModel,
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val state by viewModel.state.collectAsState()
    val context = LocalContext.current

    Column(modifier = modifier.padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                text = stringResource(R.string.share_target_label),
                style = MaterialTheme.typography.titleLarge,
                modifier = Modifier.weight(1f),
            )
            IconButton(onClick = onClose) {
                Icon(Icons.Outlined.Close, contentDescription = stringResource(R.string.action_close))
            }
        }

        Spacer(Modifier.height(12.dp))

        SectionCard {
            Text(
                text = state.input.ifBlank { stringResource(R.string.translate_empty_input) },
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }

        Spacer(Modifier.height(12.dp))

        Button(
            onClick = { viewModel.translate(TranslationChannel.SHARE) },
            enabled = state.canTranslate,
            modifier = Modifier
                .fillMaxWidth()
                .height(50.dp),
            shape = RoundedCornerShape(14.dp),
        ) {
            if (state.isTranslating) {
                TypingDots(dotSize = 7)
            } else {
                Text(stringResource(R.string.translate_action))
            }
        }

        Spacer(Modifier.height(12.dp))

        ErrorCard(message = state.error, onDismiss = viewModel::dismissError)

        val result = state.result

        if (result != null) {
            Spacer(Modifier.height(4.dp))

            Card(
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(
                    containerColor = MaterialTheme.colorScheme.primaryContainer,
                ),
            ) {
                Column(Modifier.padding(16.dp)) {
                    SelectionContainer {
                        Text(
                            text = result.displayText(state.preferRoman),
                            style = devanagariDisplay,
                            color = MaterialTheme.colorScheme.onPrimaryContainer,
                        )
                    }

                    Spacer(Modifier.height(12.dp))

                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(
                            onClick = {
                                copyToClipboard(context, result.displayText(state.preferRoman))
                            },
                            modifier = Modifier.weight(1f),
                            shape = RoundedCornerShape(12.dp),
                        ) {
                            Text(stringResource(R.string.translate_copy))
                        }

                        Button(
                            onClick = { shareText(context, result.displayText(state.preferRoman)) },
                            modifier = Modifier.weight(1f),
                            shape = RoundedCornerShape(12.dp),
                            colors = ButtonDefaults.buttonColors(
                                containerColor = MaterialTheme.colorScheme.secondaryContainer,
                                contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
                            ),
                        ) {
                            Text(stringResource(R.string.translate_share))
                        }
                    }
                }
            }
        }

        if (result == null && !state.isTranslating && state.error == null) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(vertical = 24.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    text = stringResource(R.string.translate_empty_body),
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                )
            }
        }
    }
}

private val SOURCE_LANGUAGES = listOf(
    "auto" to R.string.lang_auto,
    "hi" to R.string.lang_hindi,
    "en" to R.string.lang_english,
)

internal fun copyToClipboard(context: Context, text: String) {
    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager ?: return
    clipboard.setPrimaryClip(ClipData.newPlainText("Haryanvi", text))
}

internal fun shareText(context: Context, text: String) {
    val intent = Intent(Intent.ACTION_SEND).apply {
        type = "text/plain"
        putExtra(Intent.EXTRA_TEXT, text)
    }

    context.startActivity(Intent.createChooser(intent, null))
}

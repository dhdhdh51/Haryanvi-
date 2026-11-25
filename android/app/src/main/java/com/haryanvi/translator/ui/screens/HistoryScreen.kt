package com.haryanvi.translator.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.DeleteOutline
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
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
import androidx.compose.ui.unit.dp
import com.haryanvi.translator.R
import com.haryanvi.translator.domain.model.HistoryItem
import com.haryanvi.translator.ui.HistoryViewModel
import com.haryanvi.translator.ui.components.EmptyState
import com.haryanvi.translator.ui.components.ErrorCard
import com.haryanvi.translator.ui.theme.devanagariBody
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Saved translations, with search, favourites and delete.
 *
 * Honours the privacy switch: when history is off the screen explains that
 * rather than showing an empty list that looks broken.
 */
@Composable
fun HistoryScreen(
    viewModel: HistoryViewModel,
    onOpenPrivacy: () -> Unit,
    onReuse: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val state by viewModel.state.collectAsState()
    val items by viewModel.items.collectAsState()
    val context = LocalContext.current

    var confirmClear by remember { mutableStateOf(false) }

    Column(modifier = modifier.fillMaxSize()) {

        if (!state.historyEnabled) {
            EmptyState(
                title = stringResource(R.string.history_disabled_title),
                body = stringResource(R.string.history_disabled_body),
                mark = "🔒",
                action = {
                    TextButton(onClick = onOpenPrivacy) {
                        Text(stringResource(R.string.privacy_title))
                    }
                },
            )

            return@Column
        }

        /* ---- Filter + search ----------------------------------------- */
        Column(Modifier.padding(horizontal = 16.dp, vertical = 12.dp)) {
            SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                SegmentedButton(
                    selected = !state.favouritesOnly,
                    onClick = { viewModel.setFavouritesOnly(false) },
                    shape = SegmentedButtonDefaults.itemShape(index = 0, count = 2),
                    label = { Text(stringResource(R.string.history_title)) },
                )
                SegmentedButton(
                    selected = state.favouritesOnly,
                    onClick = { viewModel.setFavouritesOnly(true) },
                    shape = SegmentedButtonDefaults.itemShape(index = 1, count = 2),
                    label = { Text(stringResource(R.string.favourites_title)) },
                )
            }

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = state.query,
                onValueChange = viewModel::onQueryChange,
                modifier = Modifier.fillMaxWidth(),
                placeholder = { Text(stringResource(R.string.history_search_hint)) },
                singleLine = true,
                shape = RoundedCornerShape(12.dp),
            )

            if (state.error != null) {
                Spacer(Modifier.height(10.dp))
                ErrorCard(message = state.error, onDismiss = viewModel::consumeMessage)
            }
        }

        /* ---- List ---------------------------------------------------- */
        if (items.isEmpty()) {
            val searching = state.query.isNotBlank()

            EmptyState(
                title = when {
                    searching -> stringResource(R.string.history_empty_search_title)
                    state.favouritesOnly -> stringResource(R.string.favourites_empty_title)
                    else -> stringResource(R.string.history_empty_title)
                },
                body = when {
                    searching -> stringResource(R.string.history_empty_search_body)
                    state.favouritesOnly -> stringResource(R.string.favourites_empty_body)
                    else -> stringResource(R.string.history_empty_body)
                },
                mark = if (state.favouritesOnly) "★" else "◌",
            )
        } else {
            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(
                    start = 16.dp,
                    end = 16.dp,
                    bottom = 24.dp,
                ),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(items, key = { it.localId }) { item ->
                    HistoryRow(
                        item = item,
                        onToggleFavourite = { viewModel.toggleFavourite(item) },
                        onDelete = { viewModel.delete(item) },
                        onCopy = { copyToClipboard(context, item.translatedText) },
                        onReuse = { onReuse(item.sourceText) },
                    )
                }

                if (!state.favouritesOnly && state.query.isBlank()) {
                    item {
                        Spacer(Modifier.height(8.dp))
                        TextButton(
                            onClick = { confirmClear = true },
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Icon(
                                Icons.Default.DeleteOutline,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.error,
                                modifier = Modifier.size(18.dp),
                            )
                            Spacer(Modifier.width(6.dp))
                            Text(
                                text = stringResource(R.string.history_clear_all),
                                color = MaterialTheme.colorScheme.error,
                            )
                        }
                    }
                }
            }
        }
    }

    if (confirmClear) {
        AlertDialog(
            onDismissRequest = { confirmClear = false },
            title = { Text(stringResource(R.string.history_clear_confirm_title)) },
            text = { Text(stringResource(R.string.history_clear_confirm_body)) },
            confirmButton = {
                TextButton(
                    onClick = {
                        viewModel.clearAll()
                        confirmClear = false
                    }
                ) {
                    Text(
                        text = stringResource(R.string.history_clear_all),
                        color = MaterialTheme.colorScheme.error,
                    )
                }
            },
            dismissButton = {
                TextButton(onClick = { confirmClear = false }) {
                    Text(stringResource(R.string.action_cancel))
                }
            },
        )
    }
}

@Composable
private fun HistoryRow(
    item: HistoryItem,
    onToggleFavourite: () -> Unit,
    onDelete: () -> Unit,
    onCopy: () -> Unit,
    onReuse: () -> Unit,
) {
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surface),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Column(Modifier.padding(14.dp)) {
            Text(
                text = item.sourceText,
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 2,
            )

            Spacer(Modifier.height(6.dp))

            Text(
                text = item.translatedText,
                style = devanagariBody,
                color = MaterialTheme.colorScheme.onSurface,
            )

            if (!item.romanText.isNullOrBlank()) {
                Spacer(Modifier.height(4.dp))
                Text(
                    text = item.romanText,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            Spacer(Modifier.height(8.dp))

            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = buildString {
                        item.dialectName?.let { append(it).append(" · ") }
                        append(formatTimestamp(item.createdAt))
                        if (item.channel != "app") {
                            append(" · ").append(item.channel)
                        }
                    },
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.weight(1f),
                )

                IconButton(onClick = onToggleFavourite, modifier = Modifier.size(36.dp)) {
                    Icon(
                        imageVector = if (item.isFavourite) Icons.Default.Star else Icons.Default.StarBorder,
                        contentDescription = stringResource(
                            if (item.isFavourite) {
                                R.string.history_favourite_remove
                            } else {
                                R.string.history_favourite_add
                            }
                        ),
                        tint = if (item.isFavourite) {
                            MaterialTheme.colorScheme.secondary
                        } else {
                            MaterialTheme.colorScheme.onSurfaceVariant
                        },
                        modifier = Modifier.size(20.dp),
                    )
                }

                IconButton(onClick = onCopy, modifier = Modifier.size(36.dp)) {
                    Icon(
                        Icons.Default.ContentCopy,
                        contentDescription = stringResource(R.string.translate_copy),
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.size(18.dp),
                    )
                }

                IconButton(onClick = onDelete, modifier = Modifier.size(36.dp)) {
                    Icon(
                        Icons.Default.DeleteOutline,
                        contentDescription = stringResource(R.string.history_delete),
                        tint = MaterialTheme.colorScheme.error,
                        modifier = Modifier.size(20.dp),
                    )
                }
            }

            TextButton(onClick = onReuse) {
                Text(stringResource(R.string.translate_replace))
            }
        }
    }
}

private fun formatTimestamp(millis: Long): String =
    SimpleDateFormat("d MMM, HH:mm", Locale.getDefault()).format(Date(millis))

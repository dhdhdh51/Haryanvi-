package com.haryanvi.translator.ui.screens

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.haryanvi.translator.R
import com.haryanvi.translator.domain.model.FeedbackReason

/**
 * "Report a translation" sheet.
 *
 * The suggestion field matters: an admin can promote it straight into the
 * regional lexicon, which is how the engine learns a region's actual wording.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FeedbackSheet(
    onDismiss: () -> Unit,
    onSubmit: (FeedbackReason, String, String) -> Unit,
) {
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

    var reason by remember { mutableStateOf(FeedbackReason.UNNATURAL) }
    var suggestion by remember { mutableStateOf("") }
    var comment by remember { mutableStateOf("") }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(bottom = 28.dp),
        ) {
            Text(
                text = stringResource(R.string.feedback_title),
                style = MaterialTheme.typography.titleLarge,
            )

            Spacer(Modifier.height(4.dp))

            Text(
                text = stringResource(R.string.feedback_subtitle),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(18.dp))

            Text(
                text = stringResource(R.string.feedback_reason),
                style = MaterialTheme.typography.labelLarge,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )

            Spacer(Modifier.height(6.dp))

            REASONS.forEach { (value, labelRes) ->
                androidx.compose.foundation.layout.Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .selectable(
                            selected = reason == value,
                            onClick = { reason = value },
                        )
                        .padding(vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    RadioButton(
                        selected = reason == value,
                        onClick = { reason = value },
                    )
                    Text(
                        text = stringResource(labelRes),
                        style = MaterialTheme.typography.bodyLarge,
                    )
                }
            }

            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = suggestion,
                onValueChange = { suggestion = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text(stringResource(R.string.feedback_suggestion)) },
                shape = RoundedCornerShape(14.dp),
                minLines = 2,
            )

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = comment,
                onValueChange = { comment = it },
                modifier = Modifier.fillMaxWidth(),
                label = { Text(stringResource(R.string.feedback_comment)) },
                shape = RoundedCornerShape(14.dp),
                minLines = 2,
            )

            Spacer(Modifier.height(18.dp))

            Button(
                onClick = { onSubmit(reason, suggestion, comment) },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp),
                shape = RoundedCornerShape(14.dp),
            ) {
                Text(stringResource(R.string.feedback_submit))
            }

            TextButton(
                onClick = onDismiss,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.action_cancel))
            }
        }
    }
}

private val REASONS = listOf(
    FeedbackReason.WRONG_MEANING to R.string.feedback_reason_meaning,
    FeedbackReason.WRONG_DIALECT to R.string.feedback_reason_dialect,
    FeedbackReason.UNNATURAL to R.string.feedback_reason_unnatural,
    FeedbackReason.SCRIPT_ISSUE to R.string.feedback_reason_script,
    FeedbackReason.OFFENSIVE to R.string.feedback_reason_offensive,
    FeedbackReason.OTHER to R.string.feedback_reason_other,
)

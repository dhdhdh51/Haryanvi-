package com.haryanvi.translator.share

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Modifier
import androidx.lifecycle.viewmodel.compose.viewModel
import com.haryanvi.translator.HaryanviApp
import com.haryanvi.translator.ui.TranslatorViewModel
import com.haryanvi.translator.ui.screens.ShareTranslatePanel
import com.haryanvi.translator.ui.theme.HaryanviTheme

/**
 * Share-to-translate.
 *
 * Handles two entry points, both user-initiated:
 *   ACTION_SEND         — "Share" from any app that offers text.
 *   ACTION_PROCESS_TEXT — "Translate to Haryanvi" in the text-selection menu.
 *
 * It receives only the text the user chose to send. It does not read the
 * source app, its screen or its other content, and it never sends anything
 * back automatically — the user copies or shares the result themselves.
 */
class ShareTranslateActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val sharedText = intent?.readSharedText()?.trim().orEmpty()

        enableEdgeToEdge()

        setContent {
            HaryanviTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    val container = HaryanviApp.container()

                    val viewModel: TranslatorViewModel = viewModel(
                        factory = TranslatorViewModel.Factory(
                            container.translationRepository,
                            container.settingsRepository,
                        )
                    )

                    LaunchedEffect(sharedText) {
                        if (sharedText.isNotBlank()) {
                            viewModel.prefill(sharedText)
                        }
                    }

                    ShareTranslatePanel(
                        viewModel = viewModel,
                        onClose = { finish() },
                    )
                }
            }
        }
    }

    private fun Intent.readSharedText(): String? = when (action) {
        Intent.ACTION_SEND -> getStringExtra(Intent.EXTRA_TEXT)

        Intent.ACTION_PROCESS_TEXT ->
            getCharSequenceExtra(Intent.EXTRA_PROCESS_TEXT)?.toString()
                ?: getCharSequenceExtra(Intent.EXTRA_PROCESS_TEXT_READONLY)?.toString()

        else -> null
    }
}

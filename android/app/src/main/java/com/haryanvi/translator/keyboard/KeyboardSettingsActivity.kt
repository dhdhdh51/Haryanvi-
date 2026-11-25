package com.haryanvi.translator.keyboard

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.haryanvi.translator.R
import com.haryanvi.translator.ui.screens.KeyboardSetupScreen
import com.haryanvi.translator.ui.theme.HaryanviTheme

/**
 * The settings screen Android links to from the system keyboard list, and
 * that the keyboard's own gear button opens.
 *
 * Deliberately standalone: it must work while the user is mid-typing in
 * another app, without disturbing the main activity's back stack.
 */
class KeyboardSettingsActivity : ComponentActivity() {

    @OptIn(ExperimentalMaterial3Api::class)
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        enableEdgeToEdge()

        setContent {
            HaryanviTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    Scaffold(
                        topBar = {
                            TopAppBar(
                                title = { Text(stringResource(R.string.keyboard_settings_title)) },
                                navigationIcon = {
                                    IconButton(onClick = { finish() }) {
                                        Icon(
                                            Icons.AutoMirrored.Filled.ArrowBack,
                                            contentDescription = stringResource(R.string.action_back),
                                        )
                                    }
                                },
                            )
                        },
                    ) { padding ->
                        Box(Modifier.padding(padding)) {
                            KeyboardSetupScreen()
                        }
                    }
                }
            }
        }
    }
}

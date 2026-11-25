package com.haryanvi.translator.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

private val DarkScheme = darkColorScheme(
    primary = ElectricBlue,
    onPrimary = Color.White,
    primaryContainer = ElectricBlueDim,
    onPrimaryContainer = Color.White,

    secondary = MaroonLight,
    onSecondary = Color.White,
    secondaryContainer = Maroon,
    onSecondaryContainer = Color.White,

    tertiary = SuccessGreen,
    onTertiary = Navy900,

    background = Navy900,
    onBackground = TextPrimary,

    surface = Navy700,
    onSurface = TextPrimary,
    surfaceVariant = Navy600,
    onSurfaceVariant = TextMuted,
    surfaceContainer = Navy700,
    surfaceContainerHigh = Navy600,
    surfaceContainerHighest = Navy500,

    outline = NavyBorder,
    outlineVariant = Navy500,

    error = DangerRose,
    onError = Color.White,
    errorContainer = Color(0xFF3A1A24),
    onErrorContainer = Color(0xFFF0A8B6),

    scrim = Color(0xCC000000),
)

private val LightScheme = lightColorScheme(
    primary = ElectricBlueDim,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFDCE7FF),
    onPrimaryContainer = Color(0xFF0B2A66),

    secondary = Maroon,
    onSecondary = Color.White,

    tertiary = Color(0xFF1C8A61),
    onTertiary = Color.White,

    background = LightBackground,
    onBackground = LightOnSurface,

    surface = LightSurface,
    onSurface = LightOnSurface,
    surfaceVariant = LightSurfaceVariant,
    onSurfaceVariant = LightOnSurfaceVariant,

    outline = LightOutline,

    error = Color(0xFFBA1A33),
    onError = Color.White,
)

/**
 * App theme.
 *
 * Dynamic colour is deliberately not used: the dark navy/maroon identity is
 * part of the product, and Material You would repaint it per wallpaper.
 */
@Composable
fun HaryanviTheme(
    themePreference: String = "dark",
    content: @Composable () -> Unit,
) {
    val systemDark = isSystemInDarkTheme()

    val useDark = when (themePreference) {
        "light" -> false
        "dark" -> true
        else -> systemDark
    }

    val colorScheme = if (useDark) DarkScheme else LightScheme

    val view = LocalView.current

    if (!view.isInEditMode) {
        val context = LocalContext.current

        SideEffect {
            (context as? Activity)?.window?.let { window ->
                WindowCompat.getInsetsController(window, view).apply {
                    isAppearanceLightStatusBars = !useDark
                    isAppearanceLightNavigationBars = !useDark
                }
            }
        }
    }

    MaterialTheme(
        colorScheme = colorScheme,
        typography = AppTypography,
        content = content,
    )
}

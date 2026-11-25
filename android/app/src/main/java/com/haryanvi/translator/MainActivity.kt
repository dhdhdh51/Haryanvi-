package com.haryanvi.translator

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Translate
import androidx.compose.material3.CenterAlignedTopAppBar
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.res.stringResource
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.haryanvi.translator.di.AppContainer
import com.haryanvi.translator.floating.FloatingTranslatorService
import com.haryanvi.translator.ui.AuthViewModel
import com.haryanvi.translator.ui.HistoryViewModel
import com.haryanvi.translator.ui.SettingsViewModel
import com.haryanvi.translator.ui.TranslatorViewModel
import com.haryanvi.translator.ui.screens.AboutScreen
import com.haryanvi.translator.ui.screens.AccountScreen
import com.haryanvi.translator.ui.screens.AuthScreen
import com.haryanvi.translator.ui.screens.DialectScreen
import com.haryanvi.translator.ui.screens.FloatingSettingsScreen
import com.haryanvi.translator.ui.screens.HistoryScreen
import com.haryanvi.translator.ui.screens.KeyboardSetupScreen
import com.haryanvi.translator.ui.screens.OnboardingScreen
import com.haryanvi.translator.ui.screens.PrivacyScreen
import com.haryanvi.translator.ui.screens.SettingsScreen
import com.haryanvi.translator.ui.screens.TranslateScreen
import com.haryanvi.translator.ui.theme.HaryanviTheme
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking

/**
 * The single Activity hosting the Compose app.
 */
class MainActivity : ComponentActivity() {

    data class StartState(val onboarded: Boolean, val signedIn: Boolean)

    override fun onCreate(savedInstanceState: Bundle?) {
        // Must run before super.onCreate for the system splash to be used.
        installSplashScreen()

        super.onCreate(savedInstanceState)

        val container = (application as HaryanviApp).container

        /*
         * Reading two stored flags is the one thing that genuinely has to
         * finish before the first frame — otherwise the user watches the
         * wrong screen flash past. It is a single DataStore read plus a
         * SharedPreferences lookup, so blocking here is cheaper than the
         * flicker it prevents.
         */
        val startState = runBlocking {
            StartState(
                onboarded = container.preferencesStore.onboardingComplete.first(),
                signedIn = container.tokenStore.hasToken(),
            )
        }

        enableEdgeToEdge()

        setContent {
            HaryanviTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    AppRoot(
                        container = container,
                        startState = startState,
                        sharedText = intent?.sharedText(),
                    )
                }
            }
        }
    }

    private fun Intent.sharedText(): String? =
        if (action == Intent.ACTION_SEND) getStringExtra(Intent.EXTRA_TEXT) else null
}

/* =====================================================================
 | Routes
 * =================================================================== */

private object Routes {
    const val ONBOARDING = "onboarding"
    const val AUTH = "auth"
    const val TRANSLATE = "translate"
    const val HISTORY = "history"
    const val SETTINGS = "settings"
    const val DIALECTS = "dialects"
    const val KEYBOARD = "keyboard"
    const val FLOATING = "floating"
    const val ACCOUNT = "account"
    const val PRIVACY = "privacy"
    const val ABOUT = "about"
}

private val BOTTOM_TABS = listOf(
    Triple(Routes.TRANSLATE, R.string.nav_home, Icons.Default.Translate),
    Triple(Routes.HISTORY, R.string.nav_history, Icons.Default.History),
    Triple(Routes.SETTINGS, R.string.nav_settings, Icons.Default.Settings),
)

private val DETAIL_ROUTES = setOf(
    Routes.DIALECTS, Routes.KEYBOARD, Routes.FLOATING,
    Routes.ACCOUNT, Routes.PRIVACY, Routes.ABOUT,
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AppRoot(
    container: AppContainer,
    startState: MainActivity.StartState,
    sharedText: String?,
) {
    val context = LocalContext.current
    val navController = rememberNavController()
    val scope = rememberCoroutineScope()

    val translatorViewModel: TranslatorViewModel = viewModel(
        factory = TranslatorViewModel.Factory(
            container.translationRepository,
            container.settingsRepository,
        )
    )

    val settingsViewModel: SettingsViewModel = viewModel(
        factory = SettingsViewModel.Factory(
            container.settingsRepository,
            container.authRepository,
            container.historyRepository,
            container.translationRepository,
        )
    )

    val historyViewModel: HistoryViewModel = viewModel(
        factory = HistoryViewModel.Factory(
            container.historyRepository,
            container.settingsRepository,
        )
    )

    val authViewModel: AuthViewModel = viewModel(
        factory = AuthViewModel.Factory(
            container.authRepository,
            container.settingsRepository,
            deviceLabel = "${Build.MANUFACTURER} ${Build.MODEL}".trim(),
        )
    )

    // Text shared in from another app goes straight into the input box.
    LaunchedEffect(sharedText) {
        if (!sharedText.isNullOrBlank()) {
            translatorViewModel.prefill(sharedText)
        }
    }

    /*
     * Overlay permission is granted in system settings, so re-check it every
     * time the app comes back to the foreground rather than caching it once.
     */
    var overlayGranted by remember { mutableStateOf(Settings.canDrawOverlays(context)) }
    val lifecycleOwner = LocalLifecycleOwner.current

    LaunchedEffect(lifecycleOwner) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                overlayGranted = Settings.canDrawOverlays(context)
            }
        }

        lifecycleOwner.lifecycle.addObserver(observer)
    }

    val startDestination = when {
        !startState.onboarded -> Routes.ONBOARDING
        !startState.signedIn -> Routes.AUTH
        else -> Routes.TRANSLATE
    }

    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route

    val showBottomBar = currentRoute in BOTTOM_TABS.map { it.first }
    val showBackArrow = currentRoute in DETAIL_ROUTES
    val showTopBar = currentRoute != Routes.ONBOARDING && currentRoute != Routes.AUTH

    Scaffold(
        topBar = {
            if (showTopBar) {
                CenterAlignedTopAppBar(
                    title = { Text(titleFor(currentRoute)) },
                    navigationIcon = {
                        if (showBackArrow) {
                            IconButton(onClick = { navController.popBackStack() }) {
                                Icon(
                                    Icons.AutoMirrored.Filled.ArrowBack,
                                    contentDescription = stringResource(R.string.action_back),
                                )
                            }
                        }
                    },
                )
            }
        },
        bottomBar = {
            AnimatedVisibility(visible = showBottomBar) {
                NavigationBar {
                    BOTTOM_TABS.forEach { (route, labelRes, icon) ->
                        val selected = backStackEntry?.destination?.hierarchy
                            ?.any { it.route == route } == true

                        NavigationBarItem(
                            selected = selected,
                            onClick = {
                                navController.navigate(route) {
                                    popUpTo(navController.graph.findStartDestination().id) {
                                        saveState = true
                                    }
                                    launchSingleTop = true
                                    restoreState = true
                                }
                            },
                            icon = { Icon(icon, contentDescription = null) },
                            label = { Text(stringResource(labelRes)) },
                        )
                    }
                }
            }
        },
    ) { padding ->
        Box(Modifier.padding(padding)) {
            NavHost(navController = navController, startDestination = startDestination) {

                composable(Routes.ONBOARDING) {
                    OnboardingScreen(
                        onFinished = {
                            scope.launch { container.settingsRepository.completeOnboarding() }

                            navController.navigate(
                                if (startState.signedIn) Routes.TRANSLATE else Routes.AUTH
                            ) {
                                popUpTo(Routes.ONBOARDING) { inclusive = true }
                            }
                        },
                    )
                }

                composable(Routes.AUTH) {
                    AuthScreen(
                        viewModel = authViewModel,
                        onSignedIn = {
                            settingsViewModel.refresh()
                            historyViewModel.sync()

                            navController.navigate(Routes.TRANSLATE) {
                                popUpTo(Routes.AUTH) { inclusive = true }
                            }
                        },
                    )
                }

                composable(Routes.TRANSLATE) {
                    TranslateScreen(
                        viewModel = translatorViewModel,
                        onOpenDialects = { navController.navigate(Routes.DIALECTS) },
                    )
                }

                composable(Routes.HISTORY) {
                    HistoryScreen(
                        viewModel = historyViewModel,
                        onOpenPrivacy = { navController.navigate(Routes.PRIVACY) },
                        onReuse = { text ->
                            translatorViewModel.prefill(text)
                            navController.navigate(Routes.TRANSLATE) { launchSingleTop = true }
                        },
                    )
                }

                composable(Routes.SETTINGS) {
                    SettingsScreen(
                        viewModel = settingsViewModel,
                        onOpenDialects = { navController.navigate(Routes.DIALECTS) },
                        onOpenKeyboard = { navController.navigate(Routes.KEYBOARD) },
                        onOpenFloating = { navController.navigate(Routes.FLOATING) },
                        onOpenAccount = { navController.navigate(Routes.ACCOUNT) },
                        onOpenPrivacy = { navController.navigate(Routes.PRIVACY) },
                        onOpenAbout = { navController.navigate(Routes.ABOUT) },
                    )
                }

                composable(Routes.DIALECTS) { DialectScreen(viewModel = settingsViewModel) }

                composable(Routes.KEYBOARD) { KeyboardSetupScreen() }

                composable(Routes.FLOATING) {
                    FloatingSettingsScreen(
                        viewModel = settingsViewModel,
                        hasOverlayPermission = overlayGranted,
                        onRequestPermission = { requestOverlayPermission(context) },
                        onStartBubble = { FloatingTranslatorService.start(context) },
                        onStopBubble = { FloatingTranslatorService.stop(context) },
                    )
                }

                composable(Routes.ACCOUNT) {
                    AccountScreen(
                        viewModel = settingsViewModel,
                        onSignedOut = {
                            navController.navigate(Routes.AUTH) {
                                popUpTo(0) { inclusive = true }
                            }
                        },
                    )
                }

                composable(Routes.PRIVACY) { PrivacyScreen(viewModel = settingsViewModel) }

                composable(Routes.ABOUT) { AboutScreen(viewModel = settingsViewModel) }
            }
        }
    }
}

@Composable
private fun titleFor(route: String?): String = when (route) {
    Routes.TRANSLATE -> stringResource(R.string.translate_title)
    Routes.HISTORY -> stringResource(R.string.history_title)
    Routes.SETTINGS -> stringResource(R.string.nav_settings)
    Routes.DIALECTS -> stringResource(R.string.dialect_title)
    Routes.KEYBOARD -> stringResource(R.string.keyboard_settings_title)
    Routes.FLOATING -> stringResource(R.string.floating_title)
    Routes.ACCOUNT -> stringResource(R.string.account_title)
    Routes.PRIVACY -> stringResource(R.string.privacy_title)
    Routes.ABOUT -> stringResource(R.string.about_title)
    else -> stringResource(R.string.app_name)
}

private fun requestOverlayPermission(context: Context) {
    context.startActivity(
        Intent(
            Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
            Uri.parse("package:${context.packageName}"),
        ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    )
}

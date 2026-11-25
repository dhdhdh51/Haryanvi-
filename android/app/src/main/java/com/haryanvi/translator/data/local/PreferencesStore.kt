package com.haryanvi.translator.data.local

import android.content.Context
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.haryanvi.translator.BuildConfig
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore(name = "haryanvi_settings")

/**
 * Device-local settings.
 *
 * Mirrors the server-side preferences so the keyboard and the floating bubble
 * can read the user's dialect, tone and privacy choices synchronously-ish
 * without a network round trip on every keystroke.
 */
class PreferencesStore(private val context: Context) {

    /* ----------------------------------------------------------------
     | Server address
     * -------------------------------------------------------------- */

    val baseUrl: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[KEY_BASE_URL]?.takeIf { it.isNotBlank() } ?: DEFAULT_BASE_URL
    }

    suspend fun baseUrlOnce(): String = baseUrl.first()

    suspend fun setBaseUrl(url: String) {
        val normalised = url.trim().let { if (it.endsWith("/")) it.dropLast(1) else it }
        context.dataStore.edit { it[KEY_BASE_URL] = normalised }
    }

    /* ----------------------------------------------------------------
     | Onboarding
     * -------------------------------------------------------------- */

    val onboardingComplete: Flow<Boolean> =
        context.dataStore.data.map { it[KEY_ONBOARDED] ?: false }

    suspend fun setOnboardingComplete(complete: Boolean) {
        context.dataStore.edit { it[KEY_ONBOARDED] = complete }
    }

    /* ----------------------------------------------------------------
     | Translation preferences
     * -------------------------------------------------------------- */

    val preferences: Flow<LocalPreferences> = context.dataStore.data.map { it.toLocalPreferences() }

    suspend fun preferencesOnce(): LocalPreferences = preferences.first()

    suspend fun setDialect(code: String) {
        context.dataStore.edit { it[KEY_DIALECT] = code }
    }

    suspend fun setTone(code: String) {
        context.dataStore.edit { it[KEY_TONE] = code }
    }

    suspend fun setOutputScript(script: String) {
        context.dataStore.edit { it[KEY_OUTPUT_SCRIPT] = script }
    }

    suspend fun setIncludeRoman(include: Boolean) {
        context.dataStore.edit { it[KEY_INCLUDE_ROMAN] = include }
    }

    suspend fun setDefaultSourceLang(lang: String) {
        context.dataStore.edit { it[KEY_SOURCE_LANG] = lang }
    }

    suspend fun setSaveHistory(enabled: Boolean) {
        context.dataStore.edit { it[KEY_SAVE_HISTORY] = enabled }
    }

    suspend fun setCloudSync(enabled: Boolean) {
        context.dataStore.edit { it[KEY_CLOUD_SYNC] = enabled }
    }

    suspend fun setFloatingEnabled(enabled: Boolean) {
        context.dataStore.edit { it[KEY_FLOATING] = enabled }
    }

    suspend fun setKeyboardHaptics(enabled: Boolean) {
        context.dataStore.edit { it[KEY_HAPTICS] = enabled }
    }

    suspend fun setMaxInputChars(value: Int) {
        context.dataStore.edit { it[KEY_MAX_CHARS] = value }
    }

    /** Apply the authoritative values the server returned. */
    suspend fun applyServerPreferences(server: LocalPreferences) {
        context.dataStore.edit { prefs ->
            prefs[KEY_DIALECT] = server.dialect
            prefs[KEY_TONE] = server.tone
            prefs[KEY_OUTPUT_SCRIPT] = server.outputScript
            prefs[KEY_INCLUDE_ROMAN] = server.includeRoman
            prefs[KEY_SOURCE_LANG] = server.defaultSourceLang
            prefs[KEY_SAVE_HISTORY] = server.saveHistory
            prefs[KEY_CLOUD_SYNC] = server.cloudSync
            prefs[KEY_HAPTICS] = server.keyboardHaptics
        }
    }

    /* ----------------------------------------------------------------
     | Cached app configuration (for offline display)
     * -------------------------------------------------------------- */

    suspend fun cacheAppInfo(appName: String, privacyPolicy: String, terms: String, supportEmail: String) {
        context.dataStore.edit { prefs ->
            prefs[KEY_APP_NAME] = appName
            prefs[KEY_PRIVACY_POLICY] = privacyPolicy
            prefs[KEY_TERMS] = terms
            prefs[KEY_SUPPORT_EMAIL] = supportEmail
        }
    }

    val cachedAppInfo: Flow<CachedAppInfo> = context.dataStore.data.map { prefs ->
        CachedAppInfo(
            appName = prefs[KEY_APP_NAME] ?: "Haryanvi AI",
            privacyPolicy = prefs[KEY_PRIVACY_POLICY].orEmpty(),
            terms = prefs[KEY_TERMS].orEmpty(),
            supportEmail = prefs[KEY_SUPPORT_EMAIL].orEmpty(),
        )
    }

    suspend fun clearAccountScopedData() {
        context.dataStore.edit { prefs ->
            prefs.remove(KEY_SAVE_HISTORY)
            prefs.remove(KEY_CLOUD_SYNC)
        }
    }

    private fun Preferences.toLocalPreferences() = LocalPreferences(
        dialect = this[KEY_DIALECT] ?: "general",
        tone = this[KEY_TONE] ?: "natural",
        outputScript = this[KEY_OUTPUT_SCRIPT] ?: "devanagari",
        includeRoman = this[KEY_INCLUDE_ROMAN] ?: false,
        defaultSourceLang = this[KEY_SOURCE_LANG] ?: "auto",
        saveHistory = this[KEY_SAVE_HISTORY] ?: true,
        cloudSync = this[KEY_CLOUD_SYNC] ?: false,
        floatingEnabled = this[KEY_FLOATING] ?: false,
        keyboardHaptics = this[KEY_HAPTICS] ?: true,
        maxInputChars = this[KEY_MAX_CHARS] ?: 1200,
    )

    private companion object {
        val DEFAULT_BASE_URL: String = BuildConfig.API_BASE_URL.trimEnd('/')

        val KEY_BASE_URL = stringPreferencesKey("base_url")
        val KEY_ONBOARDED = booleanPreferencesKey("onboarding_complete")

        val KEY_DIALECT = stringPreferencesKey("dialect")
        val KEY_TONE = stringPreferencesKey("tone")
        val KEY_OUTPUT_SCRIPT = stringPreferencesKey("output_script")
        val KEY_INCLUDE_ROMAN = booleanPreferencesKey("include_roman")
        val KEY_SOURCE_LANG = stringPreferencesKey("source_lang")
        val KEY_SAVE_HISTORY = booleanPreferencesKey("save_history")
        val KEY_CLOUD_SYNC = booleanPreferencesKey("cloud_sync")
        val KEY_FLOATING = booleanPreferencesKey("floating_enabled")
        val KEY_HAPTICS = booleanPreferencesKey("keyboard_haptics")
        val KEY_MAX_CHARS = intPreferencesKey("max_input_chars")

        val KEY_APP_NAME = stringPreferencesKey("app_name")
        val KEY_PRIVACY_POLICY = stringPreferencesKey("privacy_policy")
        val KEY_TERMS = stringPreferencesKey("terms")
        val KEY_SUPPORT_EMAIL = stringPreferencesKey("support_email")
    }
}

/** Snapshot of the user's translation preferences. */
data class LocalPreferences(
    val dialect: String = "general",
    val tone: String = "natural",
    val outputScript: String = "devanagari",
    val includeRoman: Boolean = false,
    val defaultSourceLang: String = "auto",
    val saveHistory: Boolean = true,
    val cloudSync: Boolean = false,
    val floatingEnabled: Boolean = false,
    val keyboardHaptics: Boolean = true,
    val maxInputChars: Int = 1200,
)

data class CachedAppInfo(
    val appName: String = "Haryanvi AI",
    val privacyPolicy: String = "",
    val terms: String = "",
    val supportEmail: String = "",
)

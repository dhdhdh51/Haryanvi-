package com.haryanvi.translator.data.repository

import com.haryanvi.translator.data.local.LocalPreferences
import com.haryanvi.translator.data.local.PreferencesStore
import com.haryanvi.translator.data.remote.ApiClient
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.remote.map
import com.haryanvi.translator.data.remote.dto.PreferencesUpdateDto
import com.haryanvi.translator.data.remote.dto.SettingsResponseDto
import com.haryanvi.translator.data.remote.dto.UsageResponseDto
import com.haryanvi.translator.domain.model.AppInfo
import com.haryanvi.translator.domain.model.UsageSnapshot
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/**
 * App configuration, user preferences and usage.
 *
 * Every preference change is written locally first so the keyboard and bubble
 * react instantly, then sent to the server, which remains authoritative.
 */
class SettingsRepository(
    private val api: ApiClient,
    private val prefs: PreferencesStore,
) {
    private val _appInfo = MutableStateFlow(AppInfo())
    val appInfo: StateFlow<AppInfo> = _appInfo.asStateFlow()

    private val _usage = MutableStateFlow(UsageSnapshot())
    val usage: StateFlow<UsageSnapshot> = _usage.asStateFlow()

    val preferences: Flow<LocalPreferences> = prefs.preferences

    val baseUrl: Flow<String> = prefs.baseUrl

    val onboardingComplete: Flow<Boolean> = prefs.onboardingComplete

    suspend fun setBaseUrl(url: String) = prefs.setBaseUrl(url)

    suspend fun completeOnboarding() = prefs.setOnboardingComplete(true)

    /* ----------------------------------------------------------------
     | Server configuration
     * -------------------------------------------------------------- */

    /**
     * Reachable without a token, so this doubles as the "can I see the
     * server at all" check on the sign-in screen.
     */
    suspend fun loadAppSettings(authenticated: Boolean): ApiResult<AppInfo> =
        api.get("/api/v1/settings", SettingsResponseDto.serializer(), authenticated = authenticated)
            .map { response ->
                val info = AppInfo(
                    appName = response.app.appName,
                    supportEmail = response.app.supportEmail,
                    maintenanceMode = response.app.maintenanceMode,
                    maintenanceMessage = response.app.maintenanceMessage,
                    maxInputChars = response.app.maxInputChars,
                    allowRegistration = response.app.allowRegistration,
                    privacyPolicy = response.app.privacyPolicy,
                    terms = response.app.termsConditions,
                )

                _appInfo.value = info

                prefs.cacheAppInfo(
                    appName = info.appName,
                    privacyPolicy = info.privacyPolicy,
                    terms = info.terms,
                    supportEmail = info.supportEmail,
                )
                prefs.setMaxInputChars(info.maxInputChars)

                // Adopt the server's view of the account's preferences.
                response.preferences?.let { server ->
                    prefs.applyServerPreferences(
                        LocalPreferences(
                            dialect = server.dialect,
                            tone = server.tone,
                            outputScript = server.outputScript,
                            includeRoman = server.includeRoman,
                            defaultSourceLang = server.defaultSourceLang,
                            saveHistory = server.saveHistory,
                            cloudSync = server.cloudSync,
                            floatingEnabled = server.floatingEnabled,
                            keyboardHaptics = server.keyboardHaptics,
                        )
                    )
                }

                info
            }

    /* ----------------------------------------------------------------
     | Preferences
     * -------------------------------------------------------------- */

    suspend fun setDialect(code: String): ApiResult<Unit> {
        prefs.setDialect(code)

        return patchPreferences { put("dialect", code) }
    }

    suspend fun setTone(code: String): ApiResult<Unit> {
        prefs.setTone(code)

        return patchPreferences { put("tone", code) }
    }

    suspend fun setOutputScript(script: String): ApiResult<Unit> {
        prefs.setOutputScript(script)

        return patchPreferences { put("output_script", script) }
    }

    suspend fun setIncludeRoman(include: Boolean): ApiResult<Unit> {
        prefs.setIncludeRoman(include)

        return patchPreferences { put("include_roman", include) }
    }

    suspend fun setDefaultSourceLang(lang: String): ApiResult<Unit> {
        prefs.setDefaultSourceLang(lang)

        return patchPreferences { put("default_source_lang", lang) }
    }

    suspend fun setCloudSync(enabled: Boolean): ApiResult<Unit> {
        prefs.setCloudSync(enabled)

        return patchPreferences { put("cloud_sync", enabled) }
    }

    suspend fun setKeyboardHaptics(enabled: Boolean): ApiResult<Unit> {
        prefs.setKeyboardHaptics(enabled)

        return patchPreferences { put("keyboard_haptics", enabled) }
    }

    /**
     * Floating-bubble visibility is a device concern, so it stays local only.
     */
    suspend fun setFloatingEnabled(enabled: Boolean) = prefs.setFloatingEnabled(enabled)

    suspend fun setInstantMode(enabled: Boolean) = prefs.setInstantMode(enabled)

    /**
     * Turning history off clears stored translations on the server too; the
     * caller is responsible for wiping the local copy.
     *
     * @return the number of server-side entries deleted
     */
    suspend fun setSaveHistory(enabled: Boolean): ApiResult<Int> {
        prefs.setSaveHistory(enabled)

        val body = buildJsonObject { put("save_history", enabled) }

        return api.patch("/api/v1/settings", body, PreferencesUpdateDto.serializer())
            .map { it.historyCleared }
    }

    private suspend fun patchPreferences(
        build: kotlinx.serialization.json.JsonObjectBuilder.() -> Unit,
    ): ApiResult<Unit> =
        api.patch("/api/v1/settings", buildJsonObject(build), PreferencesUpdateDto.serializer())
            .map { Unit }

    /* ----------------------------------------------------------------
     | Usage
     * -------------------------------------------------------------- */

    suspend fun loadUsage(): ApiResult<UsageSnapshot> =
        api.get("/api/v1/usage", UsageResponseDto.serializer()).map { response ->
            val snapshot = UsageSnapshot(
                dailyUsed = response.usage.daily.used,
                dailyLimit = response.usage.daily.limit,
                dailyRemaining = response.usage.daily.remaining,
                dailyUnlimited = response.usage.daily.unlimited,
                monthlyUsed = response.usage.monthly.used,
                monthlyLimit = response.usage.monthly.limit,
                monthlyRemaining = response.usage.monthly.remaining,
                monthlyUnlimited = response.usage.monthly.unlimited,
                allTime = response.usage.allTime.translations,
                dailyResetAt = response.usage.resets.dailyAt,
            )

            _usage.value = snapshot

            snapshot
        }

    /**
     * Apply the usage block the translate endpoint returns in `meta`, so the
     * counter updates without a second request.
     */
    fun updateUsageFromMeta(meta: kotlinx.serialization.json.JsonObject?) {
        val usage = meta?.get("usage") as? kotlinx.serialization.json.JsonObject ?: return

        val daily = usage["daily"] as? kotlinx.serialization.json.JsonObject
        val monthly = usage["monthly"] as? kotlinx.serialization.json.JsonObject

        fun kotlinx.serialization.json.JsonObject?.int(key: String): Int? =
            (this?.get(key) as? kotlinx.serialization.json.JsonPrimitive)?.content?.toIntOrNull()

        fun kotlinx.serialization.json.JsonObject?.bool(key: String): Boolean =
            (this?.get(key) as? kotlinx.serialization.json.JsonPrimitive)?.content == "true"

        _usage.value = _usage.value.copy(
            dailyUsed = daily.int("used") ?: _usage.value.dailyUsed,
            dailyLimit = daily.int("limit") ?: _usage.value.dailyLimit,
            dailyRemaining = daily.int("remaining"),
            dailyUnlimited = daily.bool("unlimited"),
            monthlyUsed = monthly.int("used") ?: _usage.value.monthlyUsed,
            monthlyLimit = monthly.int("limit") ?: _usage.value.monthlyLimit,
            monthlyRemaining = monthly.int("remaining"),
            monthlyUnlimited = monthly.bool("unlimited"),
        )
    }
}

package com.haryanvi.translator.data.remote.dto

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/*
 * Wire models. Field names match the backend's JSON exactly; defaults are
 * supplied everywhere so a server that adds or omits a field never crashes
 * an older client.
 */

/* ------------------------------------------------------------------ Auth */

@Serializable
data class AuthResponseDto(
    val token: String = "",
    @SerialName("expires_at") val expiresAt: String = "",
    val user: UserDto = UserDto(),
)

@Serializable
data class UserDto(
    val id: String = "",
    val email: String = "",
    @SerialName("display_name") val displayName: String = "",
    val status: String = "active",
)

@Serializable
data class MessageDto(
    val message: String = "",
)

/* -------------------------------------------------------------- Dialects */

@Serializable
data class DialectsResponseDto(
    val dialects: List<DialectDto> = emptyList(),
    val tones: List<ToneDto> = emptyList(),
    @SerialName("source_languages") val sourceLanguages: List<LanguageOptionDto> = emptyList(),
    @SerialName("output_scripts") val outputScripts: List<LanguageOptionDto> = emptyList(),
)

@Serializable
data class DialectDto(
    val code: String = "",
    @SerialName("name_en") val nameEn: String = "",
    @SerialName("name_hi") val nameHi: String = "",
    @SerialName("region_label") val regionLabel: String = "",
    val description: String = "",
    @SerialName("is_default") val isDefault: Boolean = false,
)

@Serializable
data class ToneDto(
    val code: String = "",
    @SerialName("name_en") val nameEn: String = "",
    @SerialName("name_hi") val nameHi: String = "",
    val description: String = "",
    @SerialName("is_default") val isDefault: Boolean = false,
)

@Serializable
data class LanguageOptionDto(
    val code: String = "",
    @SerialName("name_en") val nameEn: String = "",
    @SerialName("name_hi") val nameHi: String = "",
)

/* ------------------------------------------------------------- Translate */

@Serializable
data class TranslateResponseDto(
    @SerialName("translated_text") val translatedText: String = "",
    @SerialName("roman_text") val romanText: String? = null,
    @SerialName("primary_text") val primaryText: String = "",
    @SerialName("source_text") val sourceText: String = "",
    @SerialName("detected_lang") val detectedLang: String = "",
    @SerialName("output_script") val outputScript: String = "devanagari",
    val dialect: DialectDto = DialectDto(),
    val tone: ToneDto = ToneDto(),
    @SerialName("history_id") val historyId: String? = null,
    @SerialName("saved_to_history") val savedToHistory: Boolean = false,
)

/* --------------------------------------------------------------- History */

@Serializable
data class HistoryListDto(
    val items: List<HistoryItemDto> = emptyList(),
)

@Serializable
data class HistoryItemDto(
    val id: String = "",
    @SerialName("source_text") val sourceText: String = "",
    @SerialName("translated_text") val translatedText: String = "",
    @SerialName("roman_text") val romanText: String? = null,
    @SerialName("source_lang") val sourceLang: String = "auto",
    @SerialName("detected_lang") val detectedLang: String? = null,
    val dialect: String? = null,
    @SerialName("dialect_name") val dialectName: String? = null,
    val tone: String = "natural",
    @SerialName("output_script") val outputScript: String = "devanagari",
    @SerialName("is_favourite") val isFavourite: Boolean = false,
    val channel: String = "app",
    @SerialName("created_at") val createdAt: String = "",
)

@Serializable
data class HistoryItemWrapperDto(
    val item: HistoryItemDto? = null,
)

@Serializable
data class HistoryClearedDto(
    val deleted: Int = 0,
    val message: String = "",
)

/* -------------------------------------------------------------- Settings */

@Serializable
data class SettingsResponseDto(
    val app: AppSettingsDto = AppSettingsDto(),
    val user: UserDto? = null,
    val preferences: PreferencesDto? = null,
)

@Serializable
data class AppSettingsDto(
    @SerialName("app_name") val appName: String = "Haryanvi AI",
    @SerialName("app_logo_url") val appLogoUrl: String = "",
    @SerialName("support_email") val supportEmail: String = "",
    @SerialName("maintenance_mode") val maintenanceMode: Boolean = false,
    @SerialName("maintenance_message") val maintenanceMessage: String = "",
    @SerialName("free_daily_limit") val freeDailyLimit: Int = 50,
    @SerialName("free_monthly_limit") val freeMonthlyLimit: Int = 800,
    @SerialName("max_input_chars") val maxInputChars: Int = 1200,
    @SerialName("allow_registration") val allowRegistration: Boolean = true,
    @SerialName("default_dialect_code") val defaultDialectCode: String = "general",
    @SerialName("default_tone_code") val defaultToneCode: String = "natural",
    @SerialName("privacy_policy") val privacyPolicy: String = "",
    @SerialName("terms_conditions") val termsConditions: String = "",
    @SerialName("min_app_version") val minAppVersion: Int = 1,
)

@Serializable
data class PreferencesDto(
    val dialect: String = "general",
    val tone: String = "natural",
    @SerialName("output_script") val outputScript: String = "devanagari",
    @SerialName("include_roman") val includeRoman: Boolean = false,
    @SerialName("default_source_lang") val defaultSourceLang: String = "auto",
    @SerialName("save_history") val saveHistory: Boolean = true,
    @SerialName("cloud_sync") val cloudSync: Boolean = false,
    @SerialName("floating_enabled") val floatingEnabled: Boolean = false,
    @SerialName("keyboard_haptics") val keyboardHaptics: Boolean = true,
    val theme: String = "dark",
)

@Serializable
data class PreferencesUpdateDto(
    val preferences: PreferencesDto = PreferencesDto(),
    @SerialName("history_cleared") val historyCleared: Int = 0,
)

/* ----------------------------------------------------------------- Usage */

@Serializable
data class UsageResponseDto(
    val account: UserDto = UserDto(),
    val usage: UsageDto = UsageDto(),
)

@Serializable
data class UsageDto(
    val daily: UsageWindowDto = UsageWindowDto(),
    val monthly: UsageWindowDto = UsageWindowDto(),
    @SerialName("all_time") val allTime: AllTimeDto = AllTimeDto(),
    @SerialName("characters_this_month") val charactersThisMonth: Long = 0,
    val resets: ResetsDto = ResetsDto(),
)

@Serializable
data class UsageWindowDto(
    val used: Int = 0,
    val limit: Int = 0,
    val remaining: Int? = null,
    val unlimited: Boolean = false,
)

@Serializable
data class AllTimeDto(
    val translations: Int = 0,
)

@Serializable
data class ResetsDto(
    @SerialName("daily_at") val dailyAt: String = "",
    @SerialName("monthly_at") val monthlyAt: String = "",
)

/* -------------------------------------------------------------- Feedback */

@Serializable
data class FeedbackResponseDto(
    @SerialName("feedback_id") val feedbackId: Long = 0,
    val message: String = "",
)

/* ---------------------------------------------------------------- Health */

@Serializable
data class HealthDto(
    val status: String = "",
    val database: String = "",
    @SerialName("ai_provider") val aiProvider: String = "",
    @SerialName("api_version") val apiVersion: String = "",
)

package com.haryanvi.translator.domain.model

/*
 * Domain models the UI binds to. Deliberately separate from the wire DTOs so
 * a backend field rename never ripples through every screen.
 */

data class Dialect(
    val code: String,
    val nameEn: String,
    val nameHi: String,
    val regionLabel: String,
    val description: String,
    val isDefault: Boolean,
) {
    companion object {
        /**
         * Offline fallback so the dialect picker is never empty — for example
         * on first launch with no connection. Replaced as soon as
         * GET /dialects succeeds.
         */
        val FALLBACK = listOf(
            Dialect("general", "General Haryanvi", "सामान्य हरियाणवी", "All Haryana", "Widely understood everywhere in Haryana.", true),
            Dialect("rohtak_jhajjar", "Rohtak & Jhajjar", "रोहतक व झज्जर", "Rohtak, Jhajjar, Bahadurgarh", "Core Deswali Haryanvi.", false),
            Dialect("hisar_bhiwani", "Hisar & Bhiwani", "हिसार व भिवानी", "Hisar, Bhiwani, Fatehabad", "Western Haryana, Bagri belt.", false),
            Dialect("sonipat_panipat", "Sonipat & Panipat", "सोनीपत व पानीपत", "Sonipat, Panipat, Samalkha", "Softer, Delhi-adjacent Haryanvi.", false),
            Dialect("jind_kaithal", "Jind & Kaithal", "जींद व कैथल", "Jind, Kaithal, Narwana", "Northern Haryanvi.", false),
        )
    }
}

data class Tone(
    val code: String,
    val nameEn: String,
    val nameHi: String,
    val description: String,
    val isDefault: Boolean,
) {
    companion object {
        val FALLBACK = listOf(
            Tone("natural", "Natural", "सहज बातचीत", "Everyday spoken Haryanvi.", true),
            Tone("youth", "Modern youth", "आजकल के छोरे", "How younger speakers text today.", false),
            Tone("village", "Traditional village", "गाम की बोली", "Older, rooted village speech.", false),
            Tone("respectful", "Respectful", "आदर भाव", "For elders, teachers and officials.", false),
            Tone("friendly", "Friendly", "यार-दोस्ती", "Warm, for close friends.", false),
            Tone("funny", "Funny", "मजाकिया", "Light and playful.", false),
        )
    }
}

/** A completed translation, whatever surface produced it. */
data class Translation(
    val sourceText: String,
    val translatedText: String,
    val romanText: String? = null,
    val primaryText: String = translatedText,
    val detectedLang: String = "",
    val outputScript: String = "devanagari",
    val dialectCode: String = "",
    val dialectName: String = "",
    val toneCode: String = "",
    val historyId: String? = null,
    val savedToHistory: Boolean = false,
) {
    /** The text to display and copy, honouring the chosen script. */
    fun displayText(preferRoman: Boolean): String = when {
        preferRoman && !romanText.isNullOrBlank() -> romanText
        else -> translatedText
    }
}

data class HistoryItem(
    val localId: Long,
    val remoteId: String?,
    val sourceText: String,
    val translatedText: String,
    val romanText: String?,
    val dialectName: String?,
    val toneCode: String,
    val isFavourite: Boolean,
    val channel: String,
    val createdAt: Long,
)

data class UsageSnapshot(
    val dailyUsed: Int = 0,
    val dailyLimit: Int = 0,
    val dailyRemaining: Int? = null,
    val dailyUnlimited: Boolean = false,
    val monthlyUsed: Int = 0,
    val monthlyLimit: Int = 0,
    val monthlyRemaining: Int? = null,
    val monthlyUnlimited: Boolean = false,
    val allTime: Int = 0,
    val dailyResetAt: String = "",
) {
    val dailyFraction: Float
        get() = when {
            dailyUnlimited || dailyLimit <= 0 -> 0f
            else -> (dailyUsed.toFloat() / dailyLimit).coerceIn(0f, 1f)
        }

    val monthlyFraction: Float
        get() = when {
            monthlyUnlimited || monthlyLimit <= 0 -> 0f
            else -> (monthlyUsed.toFloat() / monthlyLimit).coerceIn(0f, 1f)
        }
}

data class Account(
    val id: String,
    val email: String,
    val displayName: String,
)

/** Server-published configuration and legal text. */
data class AppInfo(
    val appName: String = "Haryanvi AI",
    val supportEmail: String = "",
    val maintenanceMode: Boolean = false,
    val maintenanceMessage: String = "",
    val maxInputChars: Int = 1200,
    val allowRegistration: Boolean = true,
    val privacyPolicy: String = "",
    val terms: String = "",
)

/** Reasons offered on the feedback sheet; values match the API enum. */
enum class FeedbackReason(val apiValue: String) {
    WRONG_MEANING("wrong_meaning"),
    WRONG_DIALECT("wrong_dialect"),
    UNNATURAL("unnatural"),
    SCRIPT_ISSUE("script_issue"),
    OFFENSIVE("offensive"),
    OTHER("other"),
}

/** Where a translation request came from; values match the API enum. */
enum class TranslationChannel(val apiValue: String) {
    APP("app"),
    KEYBOARD("keyboard"),
    FLOATING("floating"),
    SHARE("share"),
}

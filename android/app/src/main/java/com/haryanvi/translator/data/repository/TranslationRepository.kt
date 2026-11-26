package com.haryanvi.translator.data.repository

import com.haryanvi.translator.data.local.PreferencesStore
import com.haryanvi.translator.data.local.db.HistoryDao
import com.haryanvi.translator.data.local.db.HistoryEntity
import com.haryanvi.translator.data.remote.ApiClient
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.remote.map
import com.haryanvi.translator.data.remote.dto.DialectsResponseDto
import com.haryanvi.translator.data.remote.dto.FeedbackResponseDto
import com.haryanvi.translator.data.remote.dto.TranslateResponseDto
import com.haryanvi.translator.domain.model.Dialect
import com.haryanvi.translator.domain.model.FeedbackReason
import com.haryanvi.translator.domain.model.Tone
import com.haryanvi.translator.domain.model.Translation
import com.haryanvi.translator.domain.model.TranslationChannel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/**
 * The translation use case.
 *
 * Nothing is translated locally and no result is ever fabricated: when the
 * server has no AI provider the error is surfaced verbatim so the user is told
 * the truth rather than shown a word-swapped guess.
 */
class TranslationRepository(
    private val api: ApiClient,
    private val prefs: PreferencesStore,
    private val historyDao: HistoryDao,
) {
    private val _dialects = MutableStateFlow(Dialect.FALLBACK)
    val dialects: StateFlow<List<Dialect>> = _dialects.asStateFlow()

    private val _tones = MutableStateFlow(Tone.FALLBACK)
    val tones: StateFlow<List<Tone>> = _tones.asStateFlow()

    private var dialectsLoaded = false

    /* ----------------------------------------------------------------
     | Reference data
     * -------------------------------------------------------------- */

    /**
     * Dialects and tones are public, so this works before sign-in. Failures
     * are non-fatal — the bundled fallback list keeps the pickers usable.
     */
    suspend fun loadDialects(force: Boolean = false): ApiResult<Unit> {
        if (dialectsLoaded && !force) {
            return ApiResult.Success(Unit)
        }

        return api.get(
            "/api/v1/dialects",
            DialectsResponseDto.serializer(),
            authenticated = false,
        ).map { response ->
            if (response.dialects.isNotEmpty()) {
                _dialects.value = response.dialects.map { dto ->
                    Dialect(
                        code = dto.code,
                        nameEn = dto.nameEn,
                        nameHi = dto.nameHi,
                        regionLabel = dto.regionLabel,
                        description = dto.description,
                        isDefault = dto.isDefault,
                    )
                }
            }

            if (response.tones.isNotEmpty()) {
                _tones.value = response.tones.map { dto ->
                    Tone(
                        code = dto.code,
                        nameEn = dto.nameEn,
                        nameHi = dto.nameHi,
                        description = dto.description,
                        isDefault = dto.isDefault,
                    )
                }
            }

            dialectsLoaded = true
            Unit
        }
    }

    fun dialectName(code: String): String =
        _dialects.value.firstOrNull { it.code == code }?.nameEn ?: code

    fun toneName(code: String): String =
        _tones.value.firstOrNull { it.code == code }?.nameEn ?: code

    /* ----------------------------------------------------------------
     | Translate
     * -------------------------------------------------------------- */

    suspend fun translate(
        text: String,
        sourceLang: String? = null,
        dialect: String? = null,
        tone: String? = null,
        outputScript: String? = null,
        channel: TranslationChannel = TranslationChannel.APP,
    ): ApiResult<Translation> {
        val preferences = prefs.preferencesOnce()

        val body = buildJsonObject {
            put("text", text)
            put("source_lang", sourceLang ?: preferences.defaultSourceLang)
            put("dialect", dialect ?: preferences.dialect)
            put("tone", tone ?: preferences.tone)
            put("output_script", outputScript ?: preferences.outputScript)
            put("channel", channel.apiValue)
            put("mode", if (preferences.instantMode) "quick" else "ai")
            // Honour the local privacy switch immediately, without waiting for
            // the account setting to round-trip.
            put("save_history", preferences.saveHistory)
        }

        val result = api.post("/api/v1/translate", body, TranslateResponseDto.serializer())

        if (result is ApiResult.Success) {
            val dto = result.data
            val translation = dto.toTranslation(text)

            if (preferences.saveHistory) {
                cacheLocally(translation, channel)
            }

            return ApiResult.Success(translation, result.meta)
        }

        return result as ApiResult.Failure
    }

    private suspend fun cacheLocally(translation: Translation, channel: TranslationChannel) {
        val id = historyDao.insert(
            HistoryEntity(
                remoteId = translation.historyId,
                sourceText = translation.sourceText,
                translatedText = translation.translatedText,
                romanText = translation.romanText,
                detectedLang = translation.detectedLang,
                dialectCode = translation.dialectCode,
                dialectName = translation.dialectName,
                toneCode = translation.toneCode,
                outputScript = translation.outputScript,
                channel = channel.apiValue,
                synced = translation.historyId != null,
            )
        )

        // Keep the device list bounded; favourites are always preserved.
        if (historyDao.count() > LOCAL_HISTORY_CAP + 50) {
            historyDao.trimTo(LOCAL_HISTORY_CAP)
        }

        if (id <= 0) return
    }

    /* ----------------------------------------------------------------
     | Feedback
     * -------------------------------------------------------------- */

    suspend fun sendFeedback(
        reason: FeedbackReason,
        sourceText: String,
        translatedText: String,
        suggestion: String?,
        comment: String?,
        historyId: String?,
        dialect: String?,
    ): ApiResult<FeedbackResponseDto> {
        val body = buildJsonObject {
            put("reason", reason.apiValue)
            put("source_text", sourceText)
            put("translated_text", translatedText)
            if (!suggestion.isNullOrBlank()) put("suggested_text", suggestion)
            if (!comment.isNullOrBlank()) put("comment", comment)
            if (!historyId.isNullOrBlank()) put("history_id", historyId)
            if (!dialect.isNullOrBlank()) put("dialect", dialect)
        }

        return api.post("/api/v1/feedback", body, FeedbackResponseDto.serializer())
    }

    private fun TranslateResponseDto.toTranslation(originalInput: String) = Translation(
        sourceText = sourceText.ifBlank { originalInput },
        translatedText = translatedText,
        romanText = romanText,
        primaryText = primaryText.ifBlank { translatedText },
        detectedLang = detectedLang,
        outputScript = outputScript,
        dialectCode = dialect.code,
        dialectName = dialect.nameEn,
        toneCode = tone.code,
        historyId = historyId,
        savedToHistory = savedToHistory,
    )

    private companion object {
        const val LOCAL_HISTORY_CAP = 300
    }
}

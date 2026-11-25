package com.haryanvi.translator.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.repository.SettingsRepository
import com.haryanvi.translator.data.repository.TranslationRepository
import com.haryanvi.translator.domain.model.Dialect
import com.haryanvi.translator.domain.model.FeedbackReason
import com.haryanvi.translator.domain.model.Tone
import com.haryanvi.translator.domain.model.Translation
import com.haryanvi.translator.domain.model.TranslationChannel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * State for the translation screen, shared by the home dashboard and the
 * share-to-translate sheet.
 */
class TranslatorViewModel(
    private val translations: TranslationRepository,
    private val settings: SettingsRepository,
) : ViewModel() {

    data class UiState(
        val input: String = "",
        val sourceLang: String = "auto",
        val dialect: String = "general",
        val tone: String = "natural",
        val preferRoman: Boolean = false,
        val maxChars: Int = 1200,
        val isTranslating: Boolean = false,
        val result: Translation? = null,
        val error: String? = null,
        val quotaBlocked: Boolean = false,
        val providerMissing: Boolean = false,
        val feedbackSent: Boolean = false,
    ) {
        val charCount: Int get() = input.length
        val isOverLimit: Boolean get() = charCount > maxChars
        val canTranslate: Boolean
            get() = input.isNotBlank() && !isOverLimit && !isTranslating
    }

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    val dialects: StateFlow<List<Dialect>> = translations.dialects
    val tones: StateFlow<List<Tone>> = translations.tones

    init {
        viewModelScope.launch {
            translations.loadDialects()
        }

        viewModelScope.launch {
            settings.preferences.collect { prefs ->
                _state.update {
                    it.copy(
                        // Only adopt the stored defaults while the user has not
                        // overridden them for this session.
                        sourceLang = if (it.result == null) prefs.defaultSourceLang else it.sourceLang,
                        dialect = prefs.dialect,
                        tone = prefs.tone,
                        preferRoman = prefs.outputScript == "roman" || prefs.includeRoman,
                        maxChars = prefs.maxInputChars,
                    )
                }
            }
        }
    }

    /* ----------------------------------------------------------------
     | Editing
     * -------------------------------------------------------------- */

    fun onInputChange(value: String) {
        _state.update { it.copy(input = value, error = null, feedbackSent = false) }
    }

    fun setSourceLang(code: String) {
        _state.update { it.copy(sourceLang = code) }
    }

    fun setDialect(code: String) {
        _state.update { it.copy(dialect = code) }
        viewModelScope.launch { settings.setDialect(code) }
    }

    fun setTone(code: String) {
        _state.update { it.copy(tone = code) }
        viewModelScope.launch { settings.setTone(code) }
    }

    fun toggleScript() {
        _state.update { it.copy(preferRoman = !it.preferRoman) }
    }

    fun clear() {
        _state.update {
            it.copy(input = "", result = null, error = null, feedbackSent = false, quotaBlocked = false)
        }
    }

    /** "Use as input": feed the translation back for a second pass. */
    fun useResultAsInput() {
        val result = _state.value.result ?: return

        _state.update {
            it.copy(
                input = result.displayText(it.preferRoman),
                result = null,
                error = null,
            )
        }
    }

    fun prefill(text: String) {
        _state.update { it.copy(input = text, result = null, error = null) }
    }

    fun dismissError() {
        _state.update { it.copy(error = null) }
    }

    /* ----------------------------------------------------------------
     | Translate
     * -------------------------------------------------------------- */

    fun translate(channel: TranslationChannel = TranslationChannel.APP) {
        val current = _state.value

        if (!current.canTranslate) {
            if (current.input.isBlank()) {
                _state.update { it.copy(error = "Type something to translate first.") }
            } else if (current.isOverLimit) {
                _state.update {
                    it.copy(error = "That is ${current.charCount - current.maxChars} characters too long.")
                }
            }
            return
        }

        _state.update {
            it.copy(
                isTranslating = true,
                error = null,
                quotaBlocked = false,
                providerMissing = false,
                feedbackSent = false,
            )
        }

        viewModelScope.launch {
            val result = translations.translate(
                text = current.input,
                sourceLang = current.sourceLang,
                dialect = current.dialect,
                tone = current.tone,
                outputScript = if (current.preferRoman) "roman" else "devanagari",
                channel = channel,
            )

            when (result) {
                is ApiResult.Success -> {
                    settings.updateUsageFromMeta(result.meta)

                    _state.update {
                        it.copy(isTranslating = false, result = result.data, error = null)
                    }
                }

                is ApiResult.Failure -> {
                    val error = result.error

                    _state.update {
                        it.copy(
                            isTranslating = false,
                            error = error.message,
                            quotaBlocked = error.isQuotaExceeded,
                            providerMissing = error.isProviderNotConfigured,
                        )
                    }
                }
            }
        }
    }

    fun retry(channel: TranslationChannel = TranslationChannel.APP) {
        _state.update { it.copy(error = null) }
        translate(channel)
    }

    /* ----------------------------------------------------------------
     | Feedback
     * -------------------------------------------------------------- */

    fun sendFeedback(reason: FeedbackReason, suggestion: String, comment: String) {
        val result = _state.value.result ?: return

        viewModelScope.launch {
            val outcome = translations.sendFeedback(
                reason = reason,
                sourceText = result.sourceText,
                translatedText = result.translatedText,
                suggestion = suggestion.takeIf { it.isNotBlank() },
                comment = comment.takeIf { it.isNotBlank() },
                historyId = result.historyId,
                dialect = result.dialectCode,
            )

            _state.update {
                when (outcome) {
                    is ApiResult.Success -> it.copy(feedbackSent = true, error = null)
                    is ApiResult.Failure -> it.copy(error = outcome.error.message)
                }
            }
        }
    }

    class Factory(
        private val translations: TranslationRepository,
        private val settings: SettingsRepository,
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            TranslatorViewModel(translations, settings) as T
    }
}

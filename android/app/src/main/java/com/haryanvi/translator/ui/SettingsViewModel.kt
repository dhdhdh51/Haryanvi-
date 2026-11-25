package com.haryanvi.translator.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.haryanvi.translator.data.local.LocalPreferences
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.repository.AuthRepository
import com.haryanvi.translator.data.repository.HistoryRepository
import com.haryanvi.translator.data.repository.SettingsRepository
import com.haryanvi.translator.data.repository.TranslationRepository
import com.haryanvi.translator.domain.model.Account
import com.haryanvi.translator.domain.model.AppInfo
import com.haryanvi.translator.domain.model.Dialect
import com.haryanvi.translator.domain.model.Tone
import com.haryanvi.translator.domain.model.UsageSnapshot
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * Settings, privacy, account and usage.
 */
class SettingsViewModel(
    private val settings: SettingsRepository,
    private val auth: AuthRepository,
    private val history: HistoryRepository,
    private val translations: TranslationRepository,
) : ViewModel() {

    data class UiState(
        val isBusy: Boolean = false,
        val message: String? = null,
        val error: String? = null,
        val signedOut: Boolean = false,
        val accountDeleted: Boolean = false,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    val preferences: StateFlow<LocalPreferences> = settings.preferences
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), LocalPreferences())

    val appInfo: StateFlow<AppInfo> = settings.appInfo

    val usage: StateFlow<UsageSnapshot> = settings.usage

    val account: StateFlow<Account?> = auth.account

    val dialects: StateFlow<List<Dialect>> = translations.dialects

    val tones: StateFlow<List<Tone>> = translations.tones

    val baseUrl: StateFlow<String> = settings.baseUrl
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), "")

    init {
        refresh()
    }

    fun refresh() {
        viewModelScope.launch {
            translations.loadDialects()
            settings.loadAppSettings(authenticated = auth.isSignedIn)

            if (auth.isSignedIn) {
                settings.loadUsage()
            }
        }
    }

    /* ----------------------------------------------------------------
     | Preferences
     * -------------------------------------------------------------- */

    fun setDialect(code: String) = runPreference { settings.setDialect(code) }

    fun setTone(code: String) = runPreference { settings.setTone(code) }

    fun setOutputScript(script: String) = runPreference { settings.setOutputScript(script) }

    fun setIncludeRoman(include: Boolean) = runPreference { settings.setIncludeRoman(include) }

    fun setDefaultSourceLang(lang: String) = runPreference { settings.setDefaultSourceLang(lang) }

    fun setCloudSync(enabled: Boolean) = runPreference {
        val result = settings.setCloudSync(enabled)

        if (enabled && result is ApiResult.Success) {
            history.pullFromServer()
        }

        result
    }

    fun setKeyboardHaptics(enabled: Boolean) = runPreference { settings.setKeyboardHaptics(enabled) }

    fun setFloatingEnabled(enabled: Boolean) {
        viewModelScope.launch { settings.setFloatingEnabled(enabled) }
    }

    /**
     * Turning history off wipes both copies — that is the whole point of the
     * switch, so it must not leave stale data behind on either side.
     */
    fun setSaveHistory(enabled: Boolean) {
        viewModelScope.launch {
            _state.update { it.copy(isBusy = true, error = null) }

            if (!enabled) {
                history.wipeLocal()
            }

            when (val result = settings.setSaveHistory(enabled)) {
                is ApiResult.Success -> _state.update {
                    it.copy(
                        isBusy = false,
                        message = if (enabled) {
                            "History is on. New translations will be saved."
                        } else {
                            "History is off and saved translations were deleted."
                        },
                    )
                }

                is ApiResult.Failure -> _state.update {
                    it.copy(isBusy = false, error = result.error.message)
                }
            }
        }
    }

    private fun runPreference(block: suspend () -> ApiResult<*>) {
        viewModelScope.launch {
            val result = block()

            // The local value is already written, so a network failure is
            // worth mentioning but not worth reverting the UI for.
            if (result is ApiResult.Failure && !result.error.isAuthFailure) {
                _state.update { it.copy(error = result.error.message) }
            }
        }
    }

    /* ----------------------------------------------------------------
     | Account
     * -------------------------------------------------------------- */

    fun signOut(allDevices: Boolean) {
        viewModelScope.launch {
            _state.update { it.copy(isBusy = true) }

            auth.logout(allDevices)
            history.wipeLocal()

            _state.update { it.copy(isBusy = false, signedOut = true) }
        }
    }

    fun changePassword(current: String, next: String) {
        if (next.length < 8) {
            _state.update { it.copy(error = "Use at least 8 characters for the new password.") }
            return
        }

        viewModelScope.launch {
            _state.update { it.copy(isBusy = true, error = null) }

            when (val result = auth.changePassword(current, next)) {
                is ApiResult.Success -> _state.update {
                    it.copy(
                        isBusy = false,
                        signedOut = true,
                        message = "Password changed. Please sign in again.",
                    )
                }

                is ApiResult.Failure -> _state.update {
                    it.copy(isBusy = false, error = result.error.message)
                }
            }
        }
    }

    fun deleteAccount(password: String) {
        viewModelScope.launch {
            _state.update { it.copy(isBusy = true, error = null) }

            when (val result = auth.deleteAccount(password)) {
                is ApiResult.Success -> _state.update {
                    it.copy(isBusy = false, accountDeleted = true)
                }

                is ApiResult.Failure -> _state.update {
                    it.copy(isBusy = false, error = result.error.message)
                }
            }
        }
    }

    fun consumeMessage() {
        _state.update { it.copy(message = null, error = null) }
    }

    class Factory(
        private val settings: SettingsRepository,
        private val auth: AuthRepository,
        private val history: HistoryRepository,
        private val translations: TranslationRepository,
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            SettingsViewModel(settings, auth, history, translations) as T
    }
}

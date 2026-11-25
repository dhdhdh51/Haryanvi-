package com.haryanvi.translator.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.haryanvi.translator.data.remote.ApiError
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.repository.AuthRepository
import com.haryanvi.translator.data.repository.SettingsRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * Sign-in, registration and the server-address step that precedes them.
 */
class AuthViewModel(
    private val auth: AuthRepository,
    private val settings: SettingsRepository,
    private val deviceLabel: String,
) : ViewModel() {

    enum class Mode { SIGN_IN, SIGN_UP }

    data class UiState(
        val mode: Mode = Mode.SIGN_IN,
        val serverUrl: String = "",
        val email: String = "",
        val password: String = "",
        val displayName: String = "",
        val isBusy: Boolean = false,
        val error: String? = null,
        val fieldErrors: Map<String, String> = emptyMap(),
        val signedIn: Boolean = false,
        val registrationOpen: Boolean = true,
        val serverChecked: Boolean = false,
        val serverReachable: Boolean = false,
        val serverName: String = "",
    ) {
        val needsServerUrl: Boolean get() = serverUrl.isBlank()

        val canSubmit: Boolean
            get() = !isBusy &&
                serverUrl.isNotBlank() &&
                email.isNotBlank() &&
                password.isNotBlank() &&
                (mode == Mode.SIGN_IN || displayName.isNotBlank())
    }

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            val url = settings.baseUrl.first()
            _state.update { it.copy(serverUrl = url) }

            if (url.isNotBlank()) {
                checkServer()
            }
        }
    }

    fun setMode(mode: Mode) {
        _state.update { it.copy(mode = mode, error = null, fieldErrors = emptyMap()) }
    }

    fun onServerUrlChange(value: String) {
        _state.update { it.copy(serverUrl = value, error = null, serverChecked = false) }
    }

    fun onEmailChange(value: String) {
        _state.update { it.copy(email = value, error = null, fieldErrors = it.fieldErrors - "email") }
    }

    fun onPasswordChange(value: String) {
        _state.update { it.copy(password = value, error = null, fieldErrors = it.fieldErrors - "password") }
    }

    fun onDisplayNameChange(value: String) {
        _state.update {
            it.copy(displayName = value, error = null, fieldErrors = it.fieldErrors - "display_name")
        }
    }

    /**
     * Save the address and confirm something is actually listening, so a typo
     * is caught before the user types credentials into it.
     */
    fun saveServerAndCheck() {
        val raw = _state.value.serverUrl.trim()

        if (raw.isBlank()) {
            _state.update { it.copy(error = "Enter your server address.") }
            return
        }

        val normalised = if (raw.startsWith("http://") || raw.startsWith("https://")) {
            raw
        } else {
            "https://$raw"
        }

        _state.update { it.copy(serverUrl = normalised, isBusy = true, error = null) }

        viewModelScope.launch {
            settings.setBaseUrl(normalised)
            checkServer()
        }
    }

    private suspend fun checkServer() {
        when (val result = settings.loadAppSettings(authenticated = false)) {
            is ApiResult.Success -> _state.update {
                it.copy(
                    isBusy = false,
                    serverChecked = true,
                    serverReachable = true,
                    serverName = result.data.appName,
                    registrationOpen = result.data.allowRegistration,
                    error = if (result.data.maintenanceMode) result.data.maintenanceMessage else null,
                )
            }

            is ApiResult.Failure -> _state.update {
                it.copy(
                    isBusy = false,
                    serverChecked = true,
                    serverReachable = false,
                    error = describe(result.error),
                )
            }
        }
    }

    fun submit() {
        val current = _state.value

        if (!current.canSubmit) return

        if (current.mode == Mode.SIGN_UP && current.password.length < 8) {
            _state.update {
                it.copy(fieldErrors = it.fieldErrors + ("password" to "Use at least 8 characters."))
            }
            return
        }

        _state.update { it.copy(isBusy = true, error = null, fieldErrors = emptyMap()) }

        viewModelScope.launch {
            val result = when (current.mode) {
                Mode.SIGN_IN -> auth.login(current.email, current.password, deviceLabel)
                Mode.SIGN_UP -> auth.register(
                    current.email,
                    current.password,
                    current.displayName,
                    deviceLabel,
                )
            }

            when (result) {
                is ApiResult.Success -> {
                    // Pull the account's server-side preferences straight away.
                    settings.loadAppSettings(authenticated = true)

                    _state.update { it.copy(isBusy = false, signedIn = true, password = "") }
                }

                is ApiResult.Failure -> _state.update {
                    it.copy(
                        isBusy = false,
                        error = describe(result.error),
                        fieldErrors = result.error.fieldErrors,
                        // Offer the other mode when the cause is obvious.
                        mode = if (result.error.code == ApiError.CODE_CONFLICT_EMAIL) Mode.SIGN_IN else it.mode,
                    )
                }
            }
        }
    }

    private fun describe(error: ApiError): String = when {
        error.code == ApiError.CODE_NO_SERVER ->
            "Set your server address first."
        error.code == ApiError.CODE_INSECURE_SERVER ->
            error.message
        error.code == ApiError.CODE_NETWORK ->
            "Could not reach that server. Check the address and your connection."
        error.code == ApiError.CODE_TIMEOUT ->
            "The server took too long to respond."
        error.code == ApiError.CODE_BAD_RESPONSE ->
            "That address did not answer like a Haryanvi AI server."
        else -> error.message
    }

    class Factory(
        private val auth: AuthRepository,
        private val settings: SettingsRepository,
        private val deviceLabel: String,
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            AuthViewModel(auth, settings, deviceLabel) as T
    }
}

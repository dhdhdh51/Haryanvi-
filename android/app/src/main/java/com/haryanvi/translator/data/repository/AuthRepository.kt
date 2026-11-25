package com.haryanvi.translator.data.repository

import com.haryanvi.translator.data.local.PreferencesStore
import com.haryanvi.translator.data.local.SecureTokenStore
import com.haryanvi.translator.data.local.db.HistoryDao
import com.haryanvi.translator.data.remote.ApiClient
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.remote.map
import com.haryanvi.translator.data.remote.dto.AuthResponseDto
import com.haryanvi.translator.data.remote.dto.MessageDto
import com.haryanvi.translator.domain.model.Account
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/**
 * Registration, sign-in and sign-out.
 *
 * Owns the authentication state the whole app observes, and is the only place
 * that writes the bearer token.
 */
class AuthRepository(
    private val api: ApiClient,
    private val tokenStore: SecureTokenStore,
    private val prefs: PreferencesStore,
    private val historyDao: HistoryDao,
) {
    private val _account = MutableStateFlow(readCachedAccount())
    val account: StateFlow<Account?> = _account.asStateFlow()

    val isSignedIn: Boolean
        get() = tokenStore.hasToken()

    fun refreshCachedAccount() {
        _account.value = readCachedAccount()
    }

    suspend fun register(
        email: String,
        password: String,
        displayName: String,
        deviceLabel: String,
    ): ApiResult<Account> {
        val body = buildJsonObject {
            put("email", email.trim())
            put("password", password)
            put("display_name", displayName.trim())
            put("device", deviceLabel)
        }

        return api.post("/api/v1/auth/register", body, AuthResponseDto.serializer(), authenticated = false)
            .map { persist(it) }
    }

    suspend fun login(
        email: String,
        password: String,
        deviceLabel: String,
    ): ApiResult<Account> {
        val body = buildJsonObject {
            put("email", email.trim())
            put("password", password)
            put("device", deviceLabel)
        }

        return api.post("/api/v1/auth/login", body, AuthResponseDto.serializer(), authenticated = false)
            .map { persist(it) }
    }

    /**
     * Sign out. The server call is best-effort: if it fails, local credentials
     * are still cleared so the device is not left holding a live token.
     */
    suspend fun logout(allDevices: Boolean = false): ApiResult<MessageDto> {
        val body = buildJsonObject { put("all_devices", allDevices) }

        val result = api.post("/api/v1/auth/logout", body, MessageDto.serializer())

        clearLocalSession()

        return result
    }

    suspend fun changePassword(current: String, next: String): ApiResult<MessageDto> {
        val body = buildJsonObject {
            put("current_password", current)
            put("new_password", next)
        }

        val result = api.post("/api/v1/auth/password", body, MessageDto.serializer())

        // The server revokes every token on a password change, including ours.
        if (result is ApiResult.Success) {
            clearLocalSession()
        }

        return result
    }

    suspend fun deleteAccount(password: String): ApiResult<MessageDto> {
        val body = buildJsonObject {
            put("password", password)
            put("confirm", "DELETE")
        }

        val result = api.post("/api/v1/auth/delete-account", body, MessageDto.serializer())

        if (result is ApiResult.Success) {
            clearLocalSession()
            historyDao.clear()
            prefs.clearAccountScopedData()
        }

        return result
    }

    /**
     * Called when the API rejects the stored token. Wipes local credentials
     * and on-device history, since that history belongs to a session that no
     * longer exists.
     */
    suspend fun onTokenRejected() {
        clearLocalSession()
        historyDao.clear()
    }

    private suspend fun clearLocalSession() {
        tokenStore.clear()
        _account.value = null
    }

    private fun persist(response: AuthResponseDto): Account {
        tokenStore.saveToken(response.token, response.expiresAt)
        tokenStore.saveAccount(response.user.id, response.user.email, response.user.displayName)

        val account = Account(
            id = response.user.id,
            email = response.user.email,
            displayName = response.user.displayName,
        )

        _account.value = account

        return account
    }

    private fun readCachedAccount(): Account? {
        if (!tokenStore.hasToken()) return null

        val id = tokenStore.accountId() ?: return null

        return Account(
            id = id,
            email = tokenStore.accountEmail().orEmpty(),
            displayName = tokenStore.accountName().orEmpty(),
        )
    }
}

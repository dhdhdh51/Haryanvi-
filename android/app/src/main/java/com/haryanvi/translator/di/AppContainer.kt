package com.haryanvi.translator.di

import android.content.Context
import com.haryanvi.translator.data.local.PreferencesStore
import com.haryanvi.translator.data.local.SecureTokenStore
import com.haryanvi.translator.data.local.db.HaryanviDatabase
import com.haryanvi.translator.data.local.db.HistoryDao
import com.haryanvi.translator.data.remote.ApiClient
import com.haryanvi.translator.data.repository.AuthRepository
import com.haryanvi.translator.data.repository.HistoryRepository
import com.haryanvi.translator.data.repository.SettingsRepository
import com.haryanvi.translator.data.repository.TranslationRepository

/**
 * Dependency graph, assembled by hand.
 *
 * A dependency-injection framework would add annotation processing to the
 * build for a graph this small. Doing it explicitly keeps the build fast and
 * makes the wiring obvious — and the keyboard service and the floating
 * service can reach the same singletons as the Compose UI without any extra
 * machinery.
 */
class AppContainer(context: Context) {

    private val appContext: Context = context.applicationContext

    val preferencesStore: PreferencesStore by lazy { PreferencesStore(appContext) }

    val tokenStore: SecureTokenStore by lazy { SecureTokenStore(appContext) }

    private val database: HaryanviDatabase by lazy { HaryanviDatabase.get(appContext) }

    val historyDao: HistoryDao by lazy { database.historyDao() }

    val apiClient: ApiClient by lazy {
        ApiClient(
            baseUrlProvider = { preferencesStore.baseUrlOnce() },
            tokenProvider = { tokenStore.readToken() },
            onUnauthorized = { authRepository.onTokenRejected() },
        )
    }

    val authRepository: AuthRepository by lazy {
        AuthRepository(apiClient, tokenStore, preferencesStore, historyDao)
    }

    val translationRepository: TranslationRepository by lazy {
        TranslationRepository(apiClient, preferencesStore, historyDao)
    }

    val historyRepository: HistoryRepository by lazy {
        HistoryRepository(apiClient, historyDao, preferencesStore)
    }

    val settingsRepository: SettingsRepository by lazy {
        SettingsRepository(apiClient, preferencesStore)
    }
}

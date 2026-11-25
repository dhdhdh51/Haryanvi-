package com.haryanvi.translator.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.repository.HistoryRepository
import com.haryanvi.translator.data.repository.SettingsRepository
import com.haryanvi.translator.domain.model.HistoryItem
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * History and favourites.
 *
 * Reads come straight from Room so the list is instant and works offline;
 * deletes and favourite toggles are mirrored to the server.
 */
@OptIn(ExperimentalCoroutinesApi::class)
class HistoryViewModel(
    private val history: HistoryRepository,
    private val settings: SettingsRepository,
) : ViewModel() {

    data class UiState(
        val query: String = "",
        val favouritesOnly: Boolean = false,
        val historyEnabled: Boolean = true,
        val isSyncing: Boolean = false,
        val message: String? = null,
        val error: String? = null,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    /** Switches source as the query or the favourites filter changes. */
    val items: StateFlow<List<HistoryItem>> =
        _state
            .flatMapLatest { ui ->
                when {
                    ui.query.isNotBlank() -> history.search(ui.query)
                    ui.favouritesOnly -> history.observeFavourites()
                    else -> history.observeAll()
                }
            }
            .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    val count: StateFlow<Int> = history.observeCount()
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), 0)

    init {
        viewModelScope.launch {
            settings.preferences.collect { prefs ->
                _state.update { it.copy(historyEnabled = prefs.saveHistory) }
            }
        }

        // Pull anything the account saved elsewhere, if sync is on.
        viewModelScope.launch { sync() }
    }

    fun onQueryChange(value: String) {
        _state.update { it.copy(query = value) }
    }

    fun setFavouritesOnly(enabled: Boolean) {
        _state.update { it.copy(favouritesOnly = enabled, query = "") }
    }

    fun sync() {
        if (_state.value.isSyncing) return

        _state.update { it.copy(isSyncing = true) }

        viewModelScope.launch {
            history.pushUnsynced()

            val result = history.pullFromServer()

            _state.update {
                it.copy(
                    isSyncing = false,
                    error = (result as? ApiResult.Failure)
                        ?.error
                        // A signed-out user simply has nothing to sync.
                        ?.takeIf { error -> !error.isAuthFailure }
                        ?.message,
                )
            }
        }
    }

    fun toggleFavourite(item: HistoryItem) {
        viewModelScope.launch {
            val result = history.toggleFavourite(item)

            if (result is ApiResult.Failure && !result.error.isAuthFailure) {
                _state.update { it.copy(error = result.error.message) }
            }
        }
    }

    fun delete(item: HistoryItem) {
        viewModelScope.launch {
            when (val result = history.delete(item)) {
                is ApiResult.Success -> _state.update {
                    it.copy(message = "Translation deleted", error = null)
                }

                is ApiResult.Failure -> _state.update {
                    it.copy(error = result.error.message)
                }
            }
        }
    }

    fun clearAll() {
        viewModelScope.launch {
            when (val result = history.clearAll()) {
                is ApiResult.Success -> _state.update {
                    it.copy(message = "History cleared", error = null, query = "")
                }

                is ApiResult.Failure -> _state.update {
                    it.copy(error = result.error.message)
                }
            }
        }
    }

    fun consumeMessage() {
        _state.update { it.copy(message = null, error = null) }
    }

    class Factory(
        private val history: HistoryRepository,
        private val settings: SettingsRepository,
    ) : ViewModelProvider.Factory {
        @Suppress("UNCHECKED_CAST")
        override fun <T : ViewModel> create(modelClass: Class<T>): T =
            HistoryViewModel(history, settings) as T
    }
}

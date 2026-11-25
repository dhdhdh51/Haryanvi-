package com.haryanvi.translator.data.repository

import com.haryanvi.translator.data.local.PreferencesStore
import com.haryanvi.translator.data.local.db.HistoryDao
import com.haryanvi.translator.data.local.db.HistoryEntity
import com.haryanvi.translator.data.remote.ApiClient
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.data.remote.map
import com.haryanvi.translator.data.remote.dto.HistoryClearedDto
import com.haryanvi.translator.data.remote.dto.HistoryItemDto
import com.haryanvi.translator.data.remote.dto.HistoryItemWrapperDto
import com.haryanvi.translator.data.remote.dto.HistoryListDto
import com.haryanvi.translator.data.remote.dto.MessageDto
import com.haryanvi.translator.domain.model.HistoryItem
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone

/**
 * Translation history.
 *
 * Reads are local-first so the list opens instantly and works offline;
 * mutations go to the server and to Room together. Cloud sync is opt-in — with
 * it off, nothing leaves the device beyond the translation itself.
 */
class HistoryRepository(
    private val api: ApiClient,
    private val dao: HistoryDao,
    private val prefs: PreferencesStore,
) {
    fun observeAll(): Flow<List<HistoryItem>> =
        dao.observeRecent().map { rows -> rows.map { it.toDomain() } }

    fun observeFavourites(): Flow<List<HistoryItem>> =
        dao.observeFavourites().map { rows -> rows.map { it.toDomain() } }

    fun search(query: String): Flow<List<HistoryItem>> =
        dao.search(query.trim()).map { rows -> rows.map { it.toDomain() } }

    fun observeCount(): Flow<Int> = dao.observeCount()

    /* ----------------------------------------------------------------
     | Mutations
     * -------------------------------------------------------------- */

    suspend fun toggleFavourite(item: HistoryItem): ApiResult<Unit> {
        val next = !item.isFavourite

        // Optimistic: the UI reacts immediately from Room.
        dao.setFavourite(item.localId, next)

        val remoteId = item.remoteId ?: return ApiResult.Success(Unit)

        val body = buildJsonObject { put("is_favourite", next) }

        val result = api.patch(
            "/api/v1/history/$remoteId",
            body,
            HistoryItemWrapperDto.serializer(),
        )

        if (result is ApiResult.Failure) {
            // Roll back so the star does not lie about the server state.
            dao.setFavourite(item.localId, item.isFavourite)

            return ApiResult.Failure(result.error)
        }

        return ApiResult.Success(Unit)
    }

    suspend fun delete(item: HistoryItem): ApiResult<Unit> {
        dao.delete(item.localId)

        val remoteId = item.remoteId ?: return ApiResult.Success(Unit)

        val result = api.delete("/api/v1/history/$remoteId", MessageDto.serializer())

        // A 404 means it is already gone server-side, which is the state we
        // wanted anyway.
        if (result is ApiResult.Failure && result.error.code != "NOT_FOUND") {
            return ApiResult.Failure(result.error)
        }

        return ApiResult.Success(Unit)
    }

    suspend fun clearAll(): ApiResult<Int> {
        dao.clear()

        return api.delete("/api/v1/history", HistoryClearedDto.serializer())
            .map { it.deleted }
    }

    /* ----------------------------------------------------------------
     | Sync
     * -------------------------------------------------------------- */

    /**
     * Pull the server's history into Room. Only runs when the user has both
     * history and cloud sync switched on.
     */
    suspend fun pullFromServer(): ApiResult<Int> {
        val preferences = prefs.preferencesOnce()

        if (!preferences.saveHistory || !preferences.cloudSync) {
            return ApiResult.Success(0)
        }

        return api.get(
            "/api/v1/history",
            HistoryListDto.serializer(),
            query = mapOf("per_page" to "100"),
        ).map { response ->
            val entities = response.items.map { it.toEntity() }
            if (entities.isNotEmpty()) {
                dao.insertAll(entities)
            }
            entities.size
        }
    }

    /**
     * Push entries that were created while offline, or before sync was on.
     */
    suspend fun pushUnsynced(): Int {
        val preferences = prefs.preferencesOnce()

        if (!preferences.saveHistory || !preferences.cloudSync) {
            return 0
        }

        var pushed = 0

        for (entity in dao.unsynced()) {
            val body = buildJsonObject {
                put("source_text", entity.sourceText)
                put("translated_text", entity.translatedText)
                entity.romanText?.let { put("roman_text", it) }
                put("source_lang", entity.sourceLang)
                put("output_script", entity.outputScript)
                put("channel", entity.channel)
                entity.dialectCode?.let { put("dialect", it) }
                put("tone", entity.toneCode)
            }

            val result = api.post("/api/v1/history", body, HistoryItemWrapperDto.serializer())

            if (result is ApiResult.Success) {
                dao.markSynced(entity.id, result.data.item?.id)
                pushed++
            } else {
                // Stop on the first failure; the next sync will retry.
                break
            }
        }

        return pushed
    }

    /** Called when the user turns history off. */
    suspend fun wipeLocal() = dao.clear()

    /* ----------------------------------------------------------------
     | Mapping
     * -------------------------------------------------------------- */

    private fun HistoryEntity.toDomain() = HistoryItem(
        localId = id,
        remoteId = remoteId,
        sourceText = sourceText,
        translatedText = translatedText,
        romanText = romanText,
        dialectName = dialectName,
        toneCode = toneCode,
        isFavourite = isFavourite,
        channel = channel,
        createdAt = createdAt,
    )

    private fun HistoryItemDto.toEntity() = HistoryEntity(
        remoteId = id,
        sourceText = sourceText,
        translatedText = translatedText,
        romanText = romanText,
        sourceLang = sourceLang,
        detectedLang = detectedLang,
        dialectCode = dialect,
        dialectName = dialectName,
        toneCode = tone,
        outputScript = outputScript,
        isFavourite = isFavourite,
        channel = channel,
        createdAt = parseServerTimestamp(createdAt),
        synced = true,
    )

    /** The API emits "YYYY-MM-DD HH:MM:SS" in UTC. */
    private fun parseServerTimestamp(value: String): Long = runCatching {
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
            .apply { timeZone = TimeZone.getTimeZone("UTC") }
            .parse(value)
            ?.time
            ?: System.currentTimeMillis()
    }.getOrDefault(System.currentTimeMillis())
}

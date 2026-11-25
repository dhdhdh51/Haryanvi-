package com.haryanvi.translator.data.local.db

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface HistoryDao {

    @Query("SELECT * FROM history ORDER BY createdAt DESC LIMIT :limit")
    fun observeRecent(limit: Int = 200): Flow<List<HistoryEntity>>

    @Query("SELECT * FROM history WHERE isFavourite = 1 ORDER BY createdAt DESC")
    fun observeFavourites(): Flow<List<HistoryEntity>>

    /**
     * Case-insensitive contains search across both sides of the translation.
     * LIKE with a wildcard is the right tool here: history is per-device and
     * small, and FTS would not help Devanagari substring matching.
     */
    @Query(
        """
        SELECT * FROM history
        WHERE sourceText LIKE '%' || :query || '%'
           OR translatedText LIKE '%' || :query || '%'
           OR IFNULL(romanText, '') LIKE '%' || :query || '%'
        ORDER BY createdAt DESC
        LIMIT 200
        """
    )
    fun search(query: String): Flow<List<HistoryEntity>>

    @Query("SELECT * FROM history WHERE id = :id")
    suspend fun findById(id: Long): HistoryEntity?

    @Query("SELECT * FROM history WHERE remoteId = :remoteId")
    suspend fun findByRemoteId(remoteId: String): HistoryEntity?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(entity: HistoryEntity): Long

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertAll(entities: List<HistoryEntity>)

    @Query("UPDATE history SET isFavourite = :favourite WHERE id = :id")
    suspend fun setFavourite(id: Long, favourite: Boolean)

    @Query("UPDATE history SET remoteId = :remoteId, synced = 1 WHERE id = :id")
    suspend fun markSynced(id: Long, remoteId: String?)

    @Query("DELETE FROM history WHERE id = :id")
    suspend fun delete(id: Long)

    @Query("DELETE FROM history WHERE remoteId = :remoteId")
    suspend fun deleteByRemoteId(remoteId: String)

    @Query("DELETE FROM history")
    suspend fun clear()

    @Query("SELECT COUNT(*) FROM history")
    fun observeCount(): Flow<Int>

    @Query("SELECT COUNT(*) FROM history")
    suspend fun count(): Int

    @Query("SELECT * FROM history WHERE synced = 0 ORDER BY createdAt ASC LIMIT :limit")
    suspend fun unsynced(limit: Int = 50): List<HistoryEntity>

    /**
     * Keep the on-device list bounded so history never grows without limit.
     */
    @Query(
        """
        DELETE FROM history
        WHERE isFavourite = 0
          AND id NOT IN (
              SELECT id FROM history ORDER BY createdAt DESC LIMIT :keep
          )
        """
    )
    suspend fun trimTo(keep: Int)
}

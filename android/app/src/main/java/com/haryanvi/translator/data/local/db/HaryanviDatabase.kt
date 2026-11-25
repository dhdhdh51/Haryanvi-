package com.haryanvi.translator.data.local.db

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase

@Database(
    entities = [HistoryEntity::class],
    version = 1,
    exportSchema = false,
)
abstract class HaryanviDatabase : RoomDatabase() {

    abstract fun historyDao(): HistoryDao

    companion object {
        private const val NAME = "haryanvi-history.db"

        @Volatile
        private var instance: HaryanviDatabase? = null

        fun get(context: Context): HaryanviDatabase =
            instance ?: synchronized(this) {
                instance ?: build(context).also { instance = it }
            }

        private fun build(context: Context): HaryanviDatabase =
            Room.databaseBuilder(context.applicationContext, HaryanviDatabase::class.java, NAME)
                // Version 1: nothing to migrate from yet. Future versions add
                // explicit migrations rather than destructive fallbacks, so a
                // user's saved translations are never silently discarded.
                .build()
    }
}

package com.haryanvi.translator.data.local.db

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * A saved translation on this device.
 *
 * Rows exist only while the user has history enabled. `remoteId` is the
 * server's UUID when the entry was synced; a locally-created entry that has
 * not reached the server yet keeps it null and is retried later.
 */
@Entity(
    tableName = "history",
    indices = [
        Index(value = ["createdAt"]),
        Index(value = ["isFavourite", "createdAt"]),
        Index(value = ["remoteId"], unique = true),
    ],
)
data class HistoryEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0,

    val remoteId: String? = null,

    val sourceText: String,
    val translatedText: String,
    val romanText: String? = null,

    val sourceLang: String = "auto",
    val detectedLang: String? = null,

    val dialectCode: String? = null,
    val dialectName: String? = null,
    val toneCode: String = "natural",
    val outputScript: String = "devanagari",

    val isFavourite: Boolean = false,

    /** app | keyboard | floating | share */
    val channel: String = "app",

    /** Epoch millis, UTC. */
    val createdAt: Long = System.currentTimeMillis(),

    /** False until the entry has been accepted by the server. */
    val synced: Boolean = false,
)

package com.haryanvi.translator

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import com.haryanvi.translator.di.AppContainer

/**
 * Application entry point.
 *
 * Holds the dependency container so the keyboard service and the floating
 * service — neither of which has an Activity — can reach the same
 * repositories and token store the UI uses.
 */
class HaryanviApp : Application() {

    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()

        instance = this
        container = AppContainer(this)

        createFloatingNotificationChannel()
    }

    /**
     * The floating bubble runs as a foreground service, so Android requires a
     * visible notification. Registering the channel up front means the
     * notification is never silently dropped.
     */
    private fun createFloatingNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val channel = NotificationChannel(
            FLOATING_CHANNEL_ID,
            getString(R.string.floating_channel_name),
            // Low: it is an ongoing status, not an alert.
            NotificationManager.IMPORTANCE_LOW,
        ).apply {
            description = getString(R.string.floating_channel_description)
            setShowBadge(false)
            enableVibration(false)
            enableLights(false)
        }

        getSystemService(NotificationManager::class.java)?.createNotificationChannel(channel)
    }

    companion object {
        const val FLOATING_CHANNEL_ID = "floating_translator"

        @Volatile
        private var instance: HaryanviApp? = null

        /**
         * Accessor for the services. Throws rather than returning null,
         * because reaching here before Application.onCreate would be a
         * programming error, not a runtime condition to handle.
         */
        fun container(): AppContainer =
            instance?.container
                ?: error("HaryanviApp has not been created yet")
    }
}

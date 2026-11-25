package com.haryanvi.translator.floating

import android.app.Notification
import android.app.PendingIntent
import android.app.Service
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.PixelFormat
import android.os.Build
import android.os.IBinder
import android.provider.Settings
import android.view.Gravity
import android.view.LayoutInflater
import android.view.MotionEvent
import android.view.View
import android.view.WindowManager
import android.widget.Button
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.ImageButton
import android.widget.TextView
import android.widget.Toast
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.haryanvi.translator.HaryanviApp
import com.haryanvi.translator.MainActivity
import com.haryanvi.translator.R
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.domain.model.TranslationChannel
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlin.math.abs
import kotlin.math.roundToInt

/**
 * The optional floating translator.
 *
 * A draggable bubble that opens a small translation panel over whatever app
 * is in front. Runs as a foreground service with a visible, dismissible
 * notification, and only while the user has it switched on.
 *
 * PRIVACY CONTRACT:
 *  * The bubble cannot see the screen. Android overlays draw on top of other
 *    apps; they cannot read them.
 *  * The clipboard is read only when the user taps "Paste from clipboard" —
 *    never on a timer, never on launch. On Android 10+ the platform would
 *    refuse a background read anyway, and this code does not attempt one.
 *  * Nothing is translated until the user presses Translate.
 */
class FloatingTranslatorService : Service() {

    private val serviceJob: Job = SupervisorJob()
    private val scope = CoroutineScope(Dispatchers.Main.immediate + serviceJob)

    private lateinit var windowManager: WindowManager

    private var bubbleView: View? = null
    private var panelView: View? = null

    private var bubbleParams: WindowManager.LayoutParams? = null
    private var isPanelOpen = false
    private var isTranslating = false

    private var lastResult: String = ""

    override fun onBind(intent: Intent?): IBinder? = null

    /* ----------------------------------------------------------------
     | Lifecycle
     * -------------------------------------------------------------- */

    override fun onCreate() {
        super.onCreate()

        windowManager = getSystemService(Context.WINDOW_SERVICE) as WindowManager
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()

            return START_NOT_STICKY
        }

        // The overlay permission can be revoked while the service is idle.
        if (!Settings.canDrawOverlays(this)) {
            Toast.makeText(this, R.string.floating_permission_title, Toast.LENGTH_LONG).show()
            stopSelf()

            return START_NOT_STICKY
        }

        startForegroundNotification()

        if (bubbleView == null) {
            showBubble()
        }

        return START_STICKY
    }

    override fun onDestroy() {
        scope.cancel()
        removePanel()
        removeBubble()

        super.onDestroy()
    }

    /* ----------------------------------------------------------------
     | Foreground notification
     * -------------------------------------------------------------- */

    private fun startForegroundNotification() {
        val openApp = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val stop = PendingIntent.getService(
            this,
            1,
            Intent(this, FloatingTranslatorService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification: Notification = NotificationCompat.Builder(this, HaryanviApp.FLOATING_CHANNEL_ID)
            .setContentTitle(getString(R.string.floating_notification_title))
            .setContentText(getString(R.string.floating_notification_body))
            .setSmallIcon(R.drawable.ic_translate)
            .setContentIntent(stop)
            .addAction(0, getString(R.string.floating_open_app), openApp)
            .addAction(0, getString(R.string.floating_stop), stop)
            .setOngoing(true)
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(
                NOTIFICATION_ID,
                notification,
                android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE,
            )
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    /* ----------------------------------------------------------------
     | Bubble
     * -------------------------------------------------------------- */

    private fun showBubble() {
        val size = dp(54)

        val bubble = TextView(this).apply {
            text = "ह"
            textSize = 22f
            setTextColor(android.graphics.Color.WHITE)
            gravity = Gravity.CENTER
            setBackgroundResource(R.drawable.bubble_background)
            contentDescription = getString(R.string.floating_title)
            elevation = dp(6).toFloat()
        }

        val container = FrameLayout(this).apply {
            addView(bubble, FrameLayout.LayoutParams(size, size))
        }

        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            overlayType(),
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
            PixelFormat.TRANSLUCENT,
        ).apply {
            gravity = Gravity.TOP or Gravity.START
            x = dp(12)
            y = dp(180)
        }

        bubble.setOnTouchListener(DragHandler(params) { togglePanel() })

        runCatching {
            windowManager.addView(container, params)
            bubbleView = container
            bubbleParams = params
        }.onFailure {
            // Permission revoked between the check and the add.
            stopSelf()
        }
    }

    private fun removeBubble() {
        bubbleView?.let { runCatching { windowManager.removeView(it) } }
        bubbleView = null
        bubbleParams = null
    }

    /**
     * Drag with a click threshold, so moving the bubble does not also open it.
     */
    private inner class DragHandler(
        private val params: WindowManager.LayoutParams,
        private val onClick: () -> Unit,
    ) : View.OnTouchListener {

        private var initialX = 0
        private var initialY = 0
        private var touchX = 0f
        private var touchY = 0f
        private var moved = false

        override fun onTouch(view: View, event: MotionEvent): Boolean {
            when (event.action) {
                MotionEvent.ACTION_DOWN -> {
                    initialX = params.x
                    initialY = params.y
                    touchX = event.rawX
                    touchY = event.rawY
                    moved = false

                    return true
                }

                MotionEvent.ACTION_MOVE -> {
                    val dx = event.rawX - touchX
                    val dy = event.rawY - touchY

                    if (abs(dx) > CLICK_SLOP_PX || abs(dy) > CLICK_SLOP_PX) {
                        moved = true
                    }

                    params.x = (initialX + dx).roundToInt()
                    params.y = (initialY + dy).roundToInt()

                    bubbleView?.let { runCatching { windowManager.updateViewLayout(it, params) } }

                    return true
                }

                MotionEvent.ACTION_UP -> {
                    if (!moved) onClick()

                    return true
                }
            }

            return false
        }
    }

    /* ----------------------------------------------------------------
     | Panel
     * -------------------------------------------------------------- */

    private fun togglePanel() {
        if (isPanelOpen) removePanel() else showPanel()
    }

    private fun showPanel() {
        val inflater = LayoutInflater.from(this)
        val view = inflater.inflate(R.layout.floating_panel, null)

        val input = view.findViewById<EditText>(R.id.panel_input)
        val result = view.findViewById<TextView>(R.id.panel_result)
        val status = view.findViewById<TextView>(R.id.panel_status)
        val resultActions = view.findViewById<View>(R.id.panel_result_actions)
        val translateButton = view.findViewById<Button>(R.id.panel_translate)
        val pasteButton = view.findViewById<Button>(R.id.panel_paste)
        val copyButton = view.findViewById<Button>(R.id.panel_copy)
        val shareButton = view.findViewById<Button>(R.id.panel_share)

        view.findViewById<ImageButton>(R.id.panel_close).setOnClickListener { removePanel() }

        view.findViewById<ImageButton>(R.id.panel_open_app).setOnClickListener {
            startActivity(
                Intent(this, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
            removePanel()
        }

        // Clipboard access happens here and nowhere else: one explicit tap.
        pasteButton.setOnClickListener {
            val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager

            val text = clipboard?.primaryClip
                ?.takeIf { it.itemCount > 0 }
                ?.getItemAt(0)
                ?.coerceToText(this)
                ?.toString()

            if (text.isNullOrBlank()) {
                showStatus(status, getString(R.string.floating_clipboard_empty))
            } else {
                input.setText(text)
                input.setSelection(text.length)
            }
        }

        translateButton.setOnClickListener {
            val text = input.text?.toString()?.trim().orEmpty()

            if (text.isEmpty()) {
                showStatus(status, getString(R.string.translate_empty_input))

                return@setOnClickListener
            }

            if (isTranslating) return@setOnClickListener

            val container = runCatching { HaryanviApp.container() }.getOrNull()
                ?: return@setOnClickListener

            if (!container.tokenStore.hasToken()) {
                showStatus(status, getString(R.string.keyboard_sign_in_needed))

                return@setOnClickListener
            }

            isTranslating = true
            translateButton.isEnabled = false
            showStatus(status, getString(R.string.translate_working))

            scope.launch {
                val outcome = container.translationRepository.translate(
                    text = text,
                    channel = TranslationChannel.FLOATING,
                )

                isTranslating = false
                translateButton.isEnabled = true

                when (outcome) {
                    is ApiResult.Success -> {
                        val translation = outcome.data
                        lastResult = translation.primaryText.ifBlank { translation.translatedText }

                        result.text = lastResult
                        result.visibility = View.VISIBLE
                        resultActions.visibility = View.VISIBLE
                        status.visibility = View.GONE
                    }

                    is ApiResult.Failure -> {
                        val error = outcome.error

                        showStatus(
                            status,
                            when {
                                error.isAuthFailure -> getString(R.string.keyboard_sign_in_needed)
                                error.isProviderNotConfigured ->
                                    getString(R.string.error_provider_not_configured)
                                error.isQuotaExceeded -> getString(R.string.error_quota)
                                error.isRateLimited -> getString(R.string.error_rate_limited)
                                error.code == "NETWORK_UNAVAILABLE" -> getString(R.string.error_offline)
                                else -> error.message
                            }
                        )

                        result.visibility = View.GONE
                        resultActions.visibility = View.GONE
                    }
                }
            }
        }

        copyButton.setOnClickListener {
            if (lastResult.isBlank()) return@setOnClickListener

            val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
            clipboard?.setPrimaryClip(ClipData.newPlainText("Haryanvi", lastResult))

            showStatus(status, getString(R.string.translate_copied))
        }

        shareButton.setOnClickListener {
            if (lastResult.isBlank()) return@setOnClickListener

            startActivity(
                Intent.createChooser(
                    Intent(Intent.ACTION_SEND).apply {
                        type = "text/plain"
                        putExtra(Intent.EXTRA_TEXT, lastResult)
                    },
                    null,
                ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )

            removePanel()
        }

        val params = WindowManager.LayoutParams(
            (resources.displayMetrics.widthPixels * 0.92f).roundToInt(),
            WindowManager.LayoutParams.WRAP_CONTENT,
            overlayType(),
            // FOCUSABLE so the EditText can receive the soft keyboard.
            WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL,
            PixelFormat.TRANSLUCENT,
        ).apply {
            gravity = Gravity.CENTER_HORIZONTAL or Gravity.TOP
            y = dp(96)
            softInputMode = WindowManager.LayoutParams.SOFT_INPUT_ADJUST_RESIZE
        }

        runCatching {
            windowManager.addView(view, params)
            panelView = view
            isPanelOpen = true
        }.onFailure {
            stopSelf()
        }
    }

    private fun removePanel() {
        panelView?.let { runCatching { windowManager.removeView(it) } }
        panelView = null
        isPanelOpen = false
        lastResult = ""
    }

    private fun showStatus(view: TextView, message: String) {
        view.text = message
        view.visibility = View.VISIBLE
    }

    /* ----------------------------------------------------------------
     | Helpers
     * -------------------------------------------------------------- */

    private fun overlayType(): Int =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY
        } else {
            @Suppress("DEPRECATION")
            WindowManager.LayoutParams.TYPE_PHONE
        }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).roundToInt()

    companion object {
        private const val NOTIFICATION_ID = 4201
        private const val ACTION_STOP = "com.haryanvi.translator.action.STOP_FLOATING"
        private const val CLICK_SLOP_PX = 14f

        /**
         * Start the bubble. Does nothing without the overlay permission —
         * the caller is expected to have asked for it first.
         */
        fun start(context: Context) {
            if (!Settings.canDrawOverlays(context)) return

            ContextCompat.startForegroundService(
                context,
                Intent(context, FloatingTranslatorService::class.java),
            )
        }

        fun stop(context: Context) {
            context.startService(
                Intent(context, FloatingTranslatorService::class.java).setAction(ACTION_STOP)
            )
        }
    }
}

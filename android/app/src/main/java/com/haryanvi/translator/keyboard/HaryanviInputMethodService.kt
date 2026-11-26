package com.haryanvi.translator.keyboard

import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.text.TextUtils
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.EditorInfo
import android.view.inputmethod.InputConnection
import android.view.inputmethod.InputMethodManager
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.inputmethodservice.InputMethodService
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import com.haryanvi.translator.HaryanviApp
import com.haryanvi.translator.R
import com.haryanvi.translator.data.remote.ApiResult
import com.haryanvi.translator.domain.model.TranslationChannel
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlin.math.roundToInt

/**
 * The Haryanvi keyboard.
 *
 * PRIVACY CONTRACT — enforced by this class, not just promised:
 *
 *  * Keystrokes are never recorded. The service keeps no log, no buffer and
 *    no history of what you type. The only text it ever holds is the single
 *    snapshot taken when you press the translate key, plus one undo copy,
 *    and both are dropped as soon as you move to another field.
 *
 *  * Nothing is transmitted until you press हरियाणवी. Typing alone never
 *    touches the network.
 *
 *  * In password, PIN, numeric, email and other private fields the translate
 *    key is disabled outright — see [SensitiveFieldDetector]. The text in
 *    those fields cannot be sent even deliberately.
 *
 *  * The clipboard is read only when you tap the paste key.
 */
class HaryanviInputMethodService : InputMethodService() {

    private val serviceJob: Job = SupervisorJob()
    private val scope = CoroutineScope(Dispatchers.Main.immediate + serviceJob)

    private var layer: KeyboardLayer = KeyboardLayer.DEVANAGARI
    private var shifted = false
    private var capsLock = false

    private var translateAllowed = false
    private var isTranslating = false

    /**
     * The text as it was before the last translation, kept only so the undo
     * key can put it back. In memory only, cleared on field change.
     */
    private var undoSnapshot: String? = null

    private var rootView: LinearLayout? = null
    private var statusView: TextView? = null
    private var keyboardContainer: LinearLayout? = null

    private var hapticsEnabled = true

    /* ----------------------------------------------------------------
     | Lifecycle
     * -------------------------------------------------------------- */

    override fun onCreate() {
        super.onCreate()

        scope.launch {
            hapticsEnabled = runCatching {
                HaryanviApp.container().preferencesStore.preferencesOnce().keyboardHaptics
            }.getOrDefault(true)
        }
    }

    override fun onCreateInputView(): View {
        val sidePadding = dp(3)
        val bottomPadding = dp(6)

        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setBackgroundColor(ContextCompat.getColor(context, R.color.hy_keyboard_bg))
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            )
            setPadding(sidePadding, dp(5), sidePadding, bottomPadding)
        }

        // targetSdk 35 (Android 15) forces edge-to-edge, so the input window
        // now draws behind the system navigation bar. Without this the bottom
        // key row sits underneath the gesture pill / nav buttons. Lift the
        // keyboard by whatever the navigation bar occupies on each edge; on
        // devices that still reserve space for the nav bar the inset is 0, so
        // the layout is unchanged there. Left/right are handled too so a
        // landscape side nav bar does not clip the outer keys.
        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val navBars = insets.getInsets(WindowInsetsCompat.Type.navigationBars())
            view.updatePadding(
                left = sidePadding + navBars.left,
                right = sidePadding + navBars.right,
                bottom = bottomPadding + navBars.bottom,
            )
            insets
        }

        // Status strip: shows what the keyboard is doing, and carries the
        // settings shortcut the spec calls for.
        val status = TextView(this).apply {
            textSize = 12f
            setTextColor(ContextCompat.getColor(context, R.color.hy_text_muted))
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(10), dp(2), dp(8), dp(2))
            maxLines = 1
            ellipsize = TextUtils.TruncateAt.END
            text = getString(R.string.app_name)
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f)
        }

        val settingsButton = TextView(this).apply {
            text = "⚙"
            textSize = 16f
            setTextColor(ContextCompat.getColor(context, R.color.hy_text_muted))
            gravity = Gravity.CENTER
            setPadding(dp(12), dp(2), dp(12), dp(2))
            contentDescription = getString(R.string.keyboard_settings_title)
            isClickable = true
            setOnClickListener { openKeyboardSettings() }
        }

        val statusRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply { bottomMargin = dp(4) }

            addView(status)
            addView(settingsButton)
        }

        keyboardContainer = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
            )
        }

        statusView = status

        root.addView(statusRow)
        root.addView(keyboardContainer)

        rootView = root
        renderKeyboard()

        return root
    }

    override fun onStartInputView(info: EditorInfo?, restarting: Boolean) {
        super.onStartInputView(info, restarting)

        // Re-evaluate on every field: a messaging box and a password box can
        // belong to the same app.
        translateAllowed = !SensitiveFieldDetector.isSensitive(info)

        // The undo snapshot belongs to the previous field and must not leak
        // into this one.
        undoSnapshot = null
        isTranslating = false

        shifted = false
        capsLock = false

        // Numeric and phone fields get the symbol layer straight away.
        layer = when {
            info == null -> KeyboardLayer.DEVANAGARI
            isNumericField(info) -> KeyboardLayer.SYMBOLS
            else -> layer
        }

        updateStatus(
            when {
                !translateAllowed -> getString(R.string.keyboard_sensitive_blocked)
                else -> getString(R.string.app_name)
            }
        )

        renderKeyboard()
    }

    override fun onFinishInput() {
        super.onFinishInput()

        // Leaving the field drops everything held in memory.
        undoSnapshot = null
        isTranslating = false
        translateAllowed = false
    }

    override fun onDestroy() {
        scope.cancel()
        rootView = null
        statusView = null
        keyboardContainer = null

        super.onDestroy()
    }

    /* ----------------------------------------------------------------
     | View construction
     * -------------------------------------------------------------- */

    private fun renderKeyboard() {
        val container = keyboardContainer ?: return

        container.removeAllViews()

        KeyboardLayouts.lettersFor(layer).forEach { row ->
            container.addView(buildRow(row))
        }

        container.addView(
            buildRow(
                KeyboardLayouts.bottomRow(
                    layer = layer,
                    translateEnabled = translateAllowed && !isTranslating,
                    canUndo = undoSnapshot != null,
                )
            )
        )
    }

    private fun buildRow(keys: List<Key>): LinearLayout {
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dp(KEY_HEIGHT_DP),
            ).apply { topMargin = dp(3) }
        }

        keys.forEach { key -> row.addView(buildKey(key)) }

        return row
    }

    private fun buildKey(key: Key): View {
        val isTranslateKey = key.action is KeyAction.Translate
        val enabled = !isTranslateKey || (translateAllowed && !isTranslating)

        val label = TextView(this).apply {
            val keyAction = key.action

            text = when {
                keyAction is KeyAction.Space -> ""
                keyAction is KeyAction.Char && shifted -> keyAction.shifted
                else -> key.label
            }

            textSize = when {
                isTranslateKey -> 15f
                key.style == KeyStyle.SPECIAL -> 15f
                layer == KeyboardLayer.EMOJI -> 22f
                else -> 20f
            }

            setTextColor(
                when {
                    !enabled -> ContextCompat.getColor(context, R.color.hy_text_faint)
                    key.style == KeyStyle.ACCENT -> Color.WHITE
                    key.style == KeyStyle.SPECIAL -> ContextCompat.getColor(context, R.color.hy_text_muted)
                    else -> ContextCompat.getColor(context, R.color.hy_text)
                }
            )

            gravity = Gravity.CENTER
            includeFontPadding = false
            isAllCaps = false
            maxLines = 1
        }

        val background = when (key.style) {
            KeyStyle.ACCENT -> R.drawable.key_background_accent
            KeyStyle.SPECIAL -> R.drawable.key_background_special
            KeyStyle.NORMAL -> R.drawable.key_background
        }

        return FrameLayout(this).apply {
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.MATCH_PARENT, key.weight)
                .apply {
                    marginStart = dp(2)
                    marginEnd = dp(2)
                }

            setBackgroundResource(background)
            isEnabled = enabled
            isClickable = enabled
            isFocusable = enabled

            addView(
                label,
                FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT,
                )
            )

            contentDescription = describeKey(key)

            if (enabled) {
                setOnClickListener {
                    vibrate()
                    handle(key.action)
                }

                // Long press on a letter inserts its shifted face without
                // having to toggle shift.
                val action = key.action
                if (action is KeyAction.Char && action.shifted != action.value) {
                    setOnLongClickListener {
                        vibrate()
                        commit(action.shifted)
                        true
                    }
                }

                if (key.action is KeyAction.Backspace) {
                    setOnLongClickListener {
                        vibrate()
                        deleteWord()
                        true
                    }
                }

                if (key.action is KeyAction.Space) {
                    setOnLongClickListener {
                        switchToNextKeyboard()
                        true
                    }
                }
            }
        }
    }

    private fun describeKey(key: Key): String = when (key.action) {
        is KeyAction.Translate -> getString(R.string.key_translate)
        is KeyAction.Space -> getString(R.string.key_space)
        is KeyAction.Enter -> getString(R.string.key_enter)
        is KeyAction.Backspace -> "Backspace"
        is KeyAction.Shift -> "Shift"
        is KeyAction.Paste -> "Paste"
        is KeyAction.Undo -> getString(R.string.keyboard_undo)
        else -> key.label
    }

    /* ----------------------------------------------------------------
     | Key handling
     * -------------------------------------------------------------- */

    private fun handle(action: KeyAction) {
        when (action) {
            is KeyAction.Char -> {
                commit(if (shifted) action.shifted else action.value)

                // One-shot shift, unless caps lock is engaged.
                if (shifted && !capsLock) {
                    shifted = false
                    renderKeyboard()
                }
            }

            KeyAction.Space -> commit(" ")

            KeyAction.Enter -> {
                val editorAction = currentInputEditorInfo?.imeOptions?.and(EditorInfo.IME_MASK_ACTION)

                if (editorAction != null && editorAction != EditorInfo.IME_ACTION_NONE) {
                    currentInputConnection?.performEditorAction(editorAction)
                } else {
                    commit("\n")
                }
            }

            KeyAction.Backspace -> {
                val connection = currentInputConnection ?: return

                if (connection.getSelectedText(0).isNullOrEmpty()) {
                    connection.deleteSurroundingText(1, 0)
                } else {
                    connection.commitText("", 1)
                }
            }

            KeyAction.Shift -> {
                // Tap: shift on. Tap again while on: caps lock.
                when {
                    capsLock -> { capsLock = false; shifted = false }
                    shifted -> capsLock = true
                    else -> shifted = true
                }
                renderKeyboard()
            }

            KeyAction.SwitchScript -> {
                layer = if (layer == KeyboardLayer.DEVANAGARI) {
                    KeyboardLayer.QWERTY
                } else {
                    KeyboardLayer.DEVANAGARI
                }
                shifted = false
                renderKeyboard()
            }

            KeyAction.Symbols -> {
                layer = KeyboardLayer.SYMBOLS
                renderKeyboard()
            }

            KeyAction.Emoji -> {
                layer = KeyboardLayer.EMOJI
                renderKeyboard()
            }

            KeyAction.Letters -> {
                layer = KeyboardLayer.DEVANAGARI
                renderKeyboard()
            }

            KeyAction.Paste -> pasteFromClipboard()

            KeyAction.Undo -> undoTranslation()

            KeyAction.Settings -> openKeyboardSettings()

            KeyAction.NextKeyboard -> switchToNextKeyboard()

            KeyAction.Translate -> translateCurrentField()
        }
    }

    private fun commit(text: String) {
        currentInputConnection?.commitText(text, 1)
    }

    private fun deleteWord() {
        val connection = currentInputConnection ?: return
        val before = connection.getTextBeforeCursor(64, 0)?.toString() ?: return

        if (before.isEmpty()) return

        val trimmed = before.trimEnd()
        val lastSpace = trimmed.lastIndexOfAny(charArrayOf(' ', '\n', '\t'))
        val deleteCount = before.length - (if (lastSpace >= 0) lastSpace + 1 else 0)

        connection.deleteSurroundingText(deleteCount.coerceAtLeast(1), 0)
    }

    /* ----------------------------------------------------------------
     | Clipboard — only on an explicit tap
     * -------------------------------------------------------------- */

    private fun pasteFromClipboard() {
        val clipboard = getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager

        val text = clipboard?.primaryClip
            ?.takeIf { it.itemCount > 0 }
            ?.getItemAt(0)
            ?.coerceToText(this)
            ?.toString()

        if (text.isNullOrBlank()) {
            updateStatus(getString(R.string.floating_clipboard_empty), transient = true)

            return
        }

        commit(text)
    }

    /* ----------------------------------------------------------------
     | Translation
     * -------------------------------------------------------------- */

    private fun translateCurrentField() {
        if (!translateAllowed) {
            updateStatus(getString(R.string.keyboard_sensitive_blocked), transient = true)

            return
        }

        if (isTranslating) return

        val connection = currentInputConnection ?: return

        val before = connection.getTextBeforeCursor(MAX_FIELD_CHARS, 0)?.toString().orEmpty()
        val after = connection.getTextAfterCursor(MAX_FIELD_CHARS, 0)?.toString().orEmpty()
        val full = before + after

        if (full.isBlank()) {
            updateStatus(getString(R.string.keyboard_nothing_to_translate), transient = true)

            return
        }

        val container = runCatching { HaryanviApp.container() }.getOrNull() ?: return

        if (!container.tokenStore.hasToken()) {
            updateStatus(getString(R.string.keyboard_sign_in_needed), transient = true)

            return
        }

        isTranslating = true
        updateStatus(getString(R.string.keyboard_translating))
        renderKeyboard()

        scope.launch {
            val result = container.translationRepository.translate(
                text = full,
                channel = TranslationChannel.KEYBOARD,
            )

            isTranslating = false

            when (result) {
                is ApiResult.Success -> {
                    val translation = result.data
                    val replacement = translation.primaryText.ifBlank { translation.translatedText }

                    if (replacement.isNotBlank()) {
                        undoSnapshot = full
                        replaceFieldContents(before.length, after.length, replacement)
                        updateStatus(getString(R.string.app_name))
                    } else {
                        updateStatus(getString(R.string.error_provider_failed), transient = true)
                    }
                }

                is ApiResult.Failure -> {
                    val error = result.error

                    updateStatus(
                        when {
                            error.isAuthFailure -> getString(R.string.keyboard_sign_in_needed)
                            error.isProviderNotConfigured -> getString(R.string.error_provider_not_configured)
                            error.isQuotaExceeded -> getString(R.string.error_quota)
                            error.isRateLimited -> getString(R.string.error_rate_limited)
                            error.code == "NETWORK_UNAVAILABLE" -> getString(R.string.error_offline)
                            else -> error.message
                        },
                        transient = true,
                    )
                }
            }

            renderKeyboard()
        }
    }

    /**
     * Swap the field's contents for the translation in one batch edit, so the
     * host app sees a single change rather than a delete followed by a type.
     */
    private fun replaceFieldContents(beforeLength: Int, afterLength: Int, replacement: String) {
        val connection: InputConnection = currentInputConnection ?: return

        connection.beginBatchEdit()
        connection.deleteSurroundingText(beforeLength, afterLength)
        connection.commitText(replacement, 1)
        connection.endBatchEdit()
    }

    private fun undoTranslation() {
        val original = undoSnapshot ?: return
        val connection = currentInputConnection ?: return

        val before = connection.getTextBeforeCursor(MAX_FIELD_CHARS, 0)?.length ?: 0
        val after = connection.getTextAfterCursor(MAX_FIELD_CHARS, 0)?.length ?: 0

        connection.beginBatchEdit()
        connection.deleteSurroundingText(before, after)
        connection.commitText(original, 1)
        connection.endBatchEdit()

        undoSnapshot = null
        renderKeyboard()
    }

    /* ----------------------------------------------------------------
     | Helpers
     * -------------------------------------------------------------- */

    private fun updateStatus(message: String, transient: Boolean = false) {
        val view = statusView ?: return

        view.text = message

        if (transient) {
            view.postDelayed(
                {
                    if (!isTranslating) {
                        view.text = if (translateAllowed) {
                            getString(R.string.app_name)
                        } else {
                            getString(R.string.keyboard_sensitive_blocked)
                        }
                    }
                },
                STATUS_RESET_MS,
            )
        }
    }

    private fun openKeyboardSettings() {
        startActivity(
            Intent(this, KeyboardSettingsActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        )
    }

    private fun switchToNextKeyboard() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            switchToNextInputMethod(false)
        } else {
            (getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager)
                ?.showInputMethodPicker()
        }
    }

    private fun isNumericField(info: EditorInfo): Boolean {
        val klass = info.inputType and android.text.InputType.TYPE_MASK_CLASS

        return klass == android.text.InputType.TYPE_CLASS_NUMBER ||
            klass == android.text.InputType.TYPE_CLASS_PHONE
    }

    private fun vibrate() {
        if (!hapticsEnabled) return

        runCatching {
            val vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                (getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager)?.defaultVibrator
            } else {
                @Suppress("DEPRECATION")
                getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
            }

            vibrator?.vibrate(
                VibrationEffect.createOneShot(HAPTIC_MS, VibrationEffect.DEFAULT_AMPLITUDE)
            )
        }
    }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).roundToInt()

    private companion object {
        const val KEY_HEIGHT_DP = 50
        const val MAX_FIELD_CHARS = 1200
        const val STATUS_RESET_MS = 2600L
        const val HAPTIC_MS = 12L
    }
}

package com.haryanvi.translator.keyboard

import android.text.InputType
import android.view.inputmethod.EditorInfo

/**
 * Decides whether the keyboard is allowed to transmit the field's text.
 *
 * This is the privacy guarantee the project spec requires: a password, PIN,
 * card number or any field the app has marked as not auto-correctable or
 * no-personalised-learning must never be sent to the translation API, even if
 * the user taps the translate key.
 *
 * Defaults to "sensitive" for anything unrecognised, so a new Android input
 * type cannot quietly open a hole.
 */
object SensitiveFieldDetector {

    /**
     * @return true when text from this field must stay on the device
     */
    fun isSensitive(editorInfo: EditorInfo?): Boolean {
        if (editorInfo == null) {
            // No information about the field: assume the worst.
            return true
        }

        return isSensitive(editorInfo.inputType, editorInfo.imeOptions)
    }

    /**
     * The decision itself, expressed over the two primitives that matter.
     *
     * Split out from the EditorInfo overload so the rules can be unit-tested
     * on the JVM — this is the app's central privacy guarantee and it should
     * not be the one thing that only gets exercised by hand on a device.
     */
    fun isSensitive(inputType: Int, imeOptions: Int): Boolean {
        val klass = inputType and InputType.TYPE_MASK_CLASS
        val variation = inputType and InputType.TYPE_MASK_VARIATION

        /* ---- Explicit password and PIN variations ---------------------- */
        if (klass == InputType.TYPE_CLASS_TEXT) {
            when (variation) {
                InputType.TYPE_TEXT_VARIATION_PASSWORD,
                InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD,
                InputType.TYPE_TEXT_VARIATION_WEB_PASSWORD,
                -> return true
            }
        }

        if (klass == InputType.TYPE_CLASS_NUMBER &&
            variation == InputType.TYPE_NUMBER_VARIATION_PASSWORD
        ) {
            return true
        }

        /* ---- Numeric and phone fields ---------------------------------- */
        // OTPs, card numbers, account numbers and PINs all land here, and
        // none of them has any business being translated.
        if (klass == InputType.TYPE_CLASS_NUMBER ||
            klass == InputType.TYPE_CLASS_PHONE ||
            klass == InputType.TYPE_CLASS_DATETIME
        ) {
            return true
        }

        /* ---- Identifiers rather than prose ----------------------------- */
        if (klass == InputType.TYPE_CLASS_TEXT) {
            when (variation) {
                InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS,
                InputType.TYPE_TEXT_VARIATION_WEB_EMAIL_ADDRESS,
                InputType.TYPE_TEXT_VARIATION_URI,
                InputType.TYPE_TEXT_VARIATION_POSTAL_ADDRESS,
                -> return true
            }
        }

        /* ---- App-declared privacy flags -------------------------------- */
        // An app that opts out of auto-correct or personalised learning is
        // telling every keyboard that the content is private. Honour it.
        if (inputType and InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS != 0) {
            return true
        }

        if (imeOptions and EditorInfo.IME_FLAG_NO_PERSONALIZED_LEARNING != 0) {
            return true
        }

        /* ---- Anything that is not recognisable text -------------------- */
        if (klass != InputType.TYPE_CLASS_TEXT) {
            return true
        }

        return false
    }

    /**
     * Fields where the translate key is offered. Long-form message boxes are
     * the whole point of the app; a single-line search box is not.
     */
    fun isTranslatableMessageField(editorInfo: EditorInfo?): Boolean {
        if (editorInfo == null) return false

        return isTranslatableMessageField(editorInfo.inputType, editorInfo.imeOptions)
    }

    fun isTranslatableMessageField(inputType: Int, imeOptions: Int): Boolean {
        if (isSensitive(inputType, imeOptions)) return false

        val variation = inputType and InputType.TYPE_MASK_VARIATION

        return when (variation) {
            InputType.TYPE_TEXT_VARIATION_SHORT_MESSAGE,
            InputType.TYPE_TEXT_VARIATION_LONG_MESSAGE,
            InputType.TYPE_TEXT_VARIATION_NORMAL,
            InputType.TYPE_TEXT_VARIATION_WEB_EDIT_TEXT,
            InputType.TYPE_TEXT_VARIATION_PERSON_NAME,
            -> true
            else -> false
        }
    }
}

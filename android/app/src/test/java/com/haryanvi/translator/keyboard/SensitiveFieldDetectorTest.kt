package com.haryanvi.translator.keyboard

import android.text.InputType
import android.view.inputmethod.EditorInfo
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The keyboard's privacy guarantee, under test.
 *
 * The project promises that a password, PIN, OTP or any field an app marks as
 * private can never be transmitted for translation. These tests pin that
 * promise down so a future change to the input-type handling cannot quietly
 * break it.
 */
class SensitiveFieldDetectorTest {

    /* ---------------------------------------------------------------
     | Must be treated as sensitive
     * ------------------------------------------------------------- */

    @Test
    fun `text password is sensitive`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD,
                0,
            )
        )
    }

    @Test
    fun `visible password is sensitive`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD,
                0,
            )
        )
    }

    @Test
    fun `web password is sensitive`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_WEB_PASSWORD,
                0,
            )
        )
    }

    @Test
    fun `numeric password or PIN is sensitive`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD,
                0,
            )
        )
    }

    @Test
    fun `plain number field is sensitive because OTPs and card numbers live there`() {
        assertTrue(SensitiveFieldDetector.isSensitive(InputType.TYPE_CLASS_NUMBER, 0))
    }

    @Test
    fun `phone number field is sensitive`() {
        assertTrue(SensitiveFieldDetector.isSensitive(InputType.TYPE_CLASS_PHONE, 0))
    }

    @Test
    fun `datetime field is sensitive`() {
        assertTrue(SensitiveFieldDetector.isSensitive(InputType.TYPE_CLASS_DATETIME, 0))
    }

    @Test
    fun `email address field is sensitive`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS,
                0,
            )
        )
    }

    @Test
    fun `uri field is sensitive`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI,
                0,
            )
        )
    }

    @Test
    fun `postal address field is sensitive`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_POSTAL_ADDRESS,
                0,
            )
        )
    }

    @Test
    fun `no-suggestions flag is honoured as a privacy signal`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or
                    InputType.TYPE_TEXT_VARIATION_SHORT_MESSAGE or
                    InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS,
                0,
            )
        )
    }

    @Test
    fun `no-personalised-learning flag is honoured`() {
        assertTrue(
            SensitiveFieldDetector.isSensitive(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_SHORT_MESSAGE,
                EditorInfo.IME_FLAG_NO_PERSONALIZED_LEARNING,
            )
        )
    }

    @Test
    fun `null editor info defaults to sensitive`() {
        assertTrue(SensitiveFieldDetector.isSensitive(null))
    }

    @Test
    fun `unknown input class defaults to sensitive`() {
        // A class value Android does not currently define must fail closed.
        assertTrue(SensitiveFieldDetector.isSensitive(0x0000000F, 0))
    }

    /* ---------------------------------------------------------------
     | Must be allowed — these are the fields the app exists for
     * ------------------------------------------------------------- */

    @Test
    fun `short message field is translatable`() {
        val inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_SHORT_MESSAGE

        assertFalse(SensitiveFieldDetector.isSensitive(inputType, 0))
        assertTrue(SensitiveFieldDetector.isTranslatableMessageField(inputType, 0))
    }

    @Test
    fun `long message field is translatable`() {
        val inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_LONG_MESSAGE

        assertFalse(SensitiveFieldDetector.isSensitive(inputType, 0))
        assertTrue(SensitiveFieldDetector.isTranslatableMessageField(inputType, 0))
    }

    @Test
    fun `plain multiline text field is translatable`() {
        val inputType = InputType.TYPE_CLASS_TEXT or
            InputType.TYPE_TEXT_VARIATION_NORMAL or
            InputType.TYPE_TEXT_FLAG_MULTI_LINE

        assertFalse(SensitiveFieldDetector.isSensitive(inputType, 0))
    }

    @Test
    fun `capitalised sentence field is translatable`() {
        val inputType = InputType.TYPE_CLASS_TEXT or
            InputType.TYPE_TEXT_VARIATION_SHORT_MESSAGE or
            InputType.TYPE_TEXT_FLAG_CAP_SENTENCES or
            InputType.TYPE_TEXT_FLAG_AUTO_CORRECT

        assertFalse(SensitiveFieldDetector.isSensitive(inputType, 0))
        assertTrue(SensitiveFieldDetector.isTranslatableMessageField(inputType, 0))
    }

    /* ---------------------------------------------------------------
     | A sensitive field is never offered the translate key
     * ------------------------------------------------------------- */

    @Test
    fun `password field is never a translatable message field`() {
        assertFalse(
            SensitiveFieldDetector.isTranslatableMessageField(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD,
                0,
            )
        )
    }

    @Test
    fun `search box is not treated as a message field`() {
        // Not sensitive, but not a message either — no translate key.
        assertFalse(
            SensitiveFieldDetector.isTranslatableMessageField(
                InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_FILTER,
                0,
            )
        )
    }
}

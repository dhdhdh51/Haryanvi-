package com.haryanvi.translator.data.local

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import androidx.core.content.edit
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Encrypted storage for the API bearer token.
 *
 * The token is wrapped with AES-256-GCM using a key generated inside the
 * Android Keystore, so the key material never enters app memory and cannot be
 * extracted from a rooted device or lifted out of a backup. Only the
 * ciphertext lands in SharedPreferences.
 *
 * Implemented directly against the platform rather than pulling in
 * androidx.security-crypto: it is a few dozen lines, has no alpha dependency,
 * and works unchanged from API 26 upwards.
 */
class SecureTokenStore(context: Context) {

    private val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    /* ----------------------------------------------------------------
     | Token
     * -------------------------------------------------------------- */

    fun saveToken(token: String, expiresAt: String?) {
        val sealed = encrypt(token) ?: run {
            // Encryption is unavailable (hardware-backed Keystore failure).
            // Refusing to store is the right call: a plaintext fallback would
            // silently weaken the security promise.
            clear()
            return
        }

        prefs.edit {
            putString(KEY_TOKEN_CIPHER, sealed.first)
            putString(KEY_TOKEN_IV, sealed.second)
            putString(KEY_EXPIRES_AT, expiresAt)
        }
    }

    fun readToken(): String? {
        val cipherText = prefs.getString(KEY_TOKEN_CIPHER, null) ?: return null
        val iv = prefs.getString(KEY_TOKEN_IV, null) ?: return null

        return decrypt(cipherText, iv) ?: run {
            // Key invalidated (for example, the device lock was removed).
            // The stored token is unrecoverable, so drop it and ask the user
            // to sign in again.
            clear()
            null
        }
    }

    fun expiresAt(): String? = prefs.getString(KEY_EXPIRES_AT, null)

    fun hasToken(): Boolean = prefs.contains(KEY_TOKEN_CIPHER)

    fun clear() {
        prefs.edit {
            remove(KEY_TOKEN_CIPHER)
            remove(KEY_TOKEN_IV)
            remove(KEY_EXPIRES_AT)
            remove(KEY_USER_ID)
            remove(KEY_USER_EMAIL)
            remove(KEY_USER_NAME)
        }
    }

    /* ----------------------------------------------------------------
     | Cached account identity (not secret, but account-scoped)
     * -------------------------------------------------------------- */

    fun saveAccount(id: String, email: String, displayName: String) {
        prefs.edit {
            putString(KEY_USER_ID, id)
            putString(KEY_USER_EMAIL, email)
            putString(KEY_USER_NAME, displayName)
        }
    }

    fun accountId(): String? = prefs.getString(KEY_USER_ID, null)

    fun accountEmail(): String? = prefs.getString(KEY_USER_EMAIL, null)

    fun accountName(): String? = prefs.getString(KEY_USER_NAME, null)

    /* ----------------------------------------------------------------
     | Crypto
     * -------------------------------------------------------------- */

    /** @return ciphertext and IV, both base64, or null when unavailable */
    private fun encrypt(plaintext: String): Pair<String, String>? = runCatching {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, secretKey())

        val encrypted = cipher.doFinal(plaintext.toByteArray(Charsets.UTF_8))

        encrypted.base64() to cipher.iv.base64()
    }.getOrNull()

    private fun decrypt(cipherTextBase64: String, ivBase64: String): String? = runCatching {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(
            Cipher.DECRYPT_MODE,
            secretKey(),
            GCMParameterSpec(GCM_TAG_BITS, ivBase64.fromBase64()),
        )

        String(cipher.doFinal(cipherTextBase64.fromBase64()), Charsets.UTF_8)
    }.getOrNull()

    private fun secretKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }

        (keyStore.getEntry(KEY_ALIAS, null) as? KeyStore.SecretKeyEntry)?.let {
            return it.secretKey
        }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)

        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                // Deliberately not requiring user authentication: the keyboard
                // and the floating bubble must be able to translate without
                // forcing a biometric prompt mid-typing.
                .setUserAuthenticationRequired(false)
                .build()
        )

        return generator.generateKey()
    }

    private fun ByteArray.base64(): String = Base64.encodeToString(this, Base64.NO_WRAP)

    private fun String.fromBase64(): ByteArray = Base64.decode(this, Base64.NO_WRAP)

    private companion object {
        const val PREFS_NAME = "haryanvi_secure"
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val KEY_ALIAS = "haryanvi_token_key_v1"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val GCM_TAG_BITS = 128

        const val KEY_TOKEN_CIPHER = "token_cipher"
        const val KEY_TOKEN_IV = "token_iv"
        const val KEY_EXPIRES_AT = "token_expires_at"
        const val KEY_USER_ID = "user_id"
        const val KEY_USER_EMAIL = "user_email"
        const val KEY_USER_NAME = "user_name"
    }
}

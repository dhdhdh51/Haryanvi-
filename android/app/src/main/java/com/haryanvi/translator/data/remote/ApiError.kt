package com.haryanvi.translator.data.remote

/**
 * A failure the UI can act on.
 *
 * `code` mirrors the backend's machine-readable error codes so screens can
 * branch on the cause (sign in again, show the quota sheet, tell the user the
 * server has no AI provider) rather than matching on message text.
 */
data class ApiError(
    val code: String,
    val message: String,
    val httpStatus: Int = 0,
    val fieldErrors: Map<String, String> = emptyMap(),
    val retryAfterSeconds: Int? = null,
) {
    val isAuthFailure: Boolean
        get() = code == CODE_UNAUTHORIZED || httpStatus == 401

    val isBlocked: Boolean
        get() = code == CODE_FORBIDDEN && httpStatus == 403

    val isQuotaExceeded: Boolean
        get() = code == CODE_QUOTA_EXCEEDED

    val isRateLimited: Boolean
        get() = code == CODE_RATE_LIMITED

    val isProviderNotConfigured: Boolean
        get() = code == CODE_PROVIDER_NOT_CONFIGURED

    val isValidation: Boolean
        get() = code == CODE_VALIDATION_FAILED

    val isRetryable: Boolean
        get() = when (code) {
            CODE_NETWORK, CODE_TIMEOUT, CODE_PROVIDER_FAILED, CODE_SERVER_ERROR,
            CODE_DATABASE_UNAVAILABLE,
            -> true
            else -> false
        }

    companion object {
        // Returned by the backend
        const val CODE_VALIDATION_FAILED = "VALIDATION_FAILED"
        const val CODE_UNAUTHORIZED = "UNAUTHORIZED"
        const val CODE_FORBIDDEN = "FORBIDDEN"
        const val CODE_NOT_FOUND = "NOT_FOUND"
        const val CODE_CONFLICT_EMAIL = "EMAIL_ALREADY_REGISTERED"
        const val CODE_RATE_LIMITED = "RATE_LIMITED"
        const val CODE_QUOTA_EXCEEDED = "QUOTA_EXCEEDED"
        const val CODE_PROVIDER_NOT_CONFIGURED = "AI_PROVIDER_NOT_CONFIGURED"
        const val CODE_PROVIDER_FAILED = "AI_PROVIDER_FAILED"
        const val CODE_MAINTENANCE = "MAINTENANCE_MODE"
        const val CODE_SERVER_ERROR = "SERVER_ERROR"
        const val CODE_DATABASE_UNAVAILABLE = "DATABASE_UNAVAILABLE"
        const val CODE_MALFORMED_JSON = "MALFORMED_JSON"

        // Raised on the client
        const val CODE_NETWORK = "NETWORK_UNAVAILABLE"
        const val CODE_TIMEOUT = "NETWORK_TIMEOUT"
        const val CODE_BAD_RESPONSE = "BAD_RESPONSE"
        const val CODE_NO_SERVER = "NO_SERVER_CONFIGURED"
        const val CODE_INSECURE_SERVER = "INSECURE_SERVER_URL"

        fun network(message: String) = ApiError(CODE_NETWORK, message)

        fun timeout(message: String) = ApiError(CODE_TIMEOUT, message)

        fun badResponse(message: String) = ApiError(CODE_BAD_RESPONSE, message)

        fun noServer() = ApiError(
            CODE_NO_SERVER,
            "No server address is configured yet.",
        )
    }
}

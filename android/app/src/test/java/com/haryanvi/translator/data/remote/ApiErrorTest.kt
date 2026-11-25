package com.haryanvi.translator.data.remote

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Error classification drives what the user is told and whether a retry
 * button appears, so the mapping is worth asserting.
 */
class ApiErrorTest {

    @Test
    fun `unauthorized is recognised from the code`() {
        assertTrue(ApiError(ApiError.CODE_UNAUTHORIZED, "nope").isAuthFailure)
    }

    @Test
    fun `unauthorized is recognised from the status alone`() {
        // Some proxies strip the body; the status must still be enough.
        assertTrue(ApiError("SOMETHING_ELSE", "nope", httpStatus = 401).isAuthFailure)
    }

    @Test
    fun `quota exceeded is distinct from rate limiting`() {
        val quota = ApiError(ApiError.CODE_QUOTA_EXCEEDED, "used up")
        val rate = ApiError(ApiError.CODE_RATE_LIMITED, "slow down")

        assertTrue(quota.isQuotaExceeded)
        assertFalse(quota.isRateLimited)

        assertTrue(rate.isRateLimited)
        assertFalse(rate.isQuotaExceeded)
    }

    @Test
    fun `unconfigured provider is flagged so the app can explain the real cause`() {
        val error = ApiError(ApiError.CODE_PROVIDER_NOT_CONFIGURED, "no provider", httpStatus = 503)

        assertTrue(error.isProviderNotConfigured)
        // Not a transient failure — retrying will not help the user.
        assertFalse(error.isRetryable)
    }

    @Test
    fun `transient failures are retryable`() {
        listOf(
            ApiError.CODE_NETWORK,
            ApiError.CODE_TIMEOUT,
            ApiError.CODE_PROVIDER_FAILED,
            ApiError.CODE_SERVER_ERROR,
            ApiError.CODE_DATABASE_UNAVAILABLE,
        ).forEach { code ->
            assertTrue("$code should be retryable", ApiError(code, "x").isRetryable)
        }
    }

    @Test
    fun `permanent failures are not retryable`() {
        listOf(
            ApiError.CODE_VALIDATION_FAILED,
            ApiError.CODE_UNAUTHORIZED,
            ApiError.CODE_FORBIDDEN,
            ApiError.CODE_NOT_FOUND,
            ApiError.CODE_QUOTA_EXCEEDED,
            ApiError.CODE_PROVIDER_NOT_CONFIGURED,
        ).forEach { code ->
            assertFalse("$code should not be retryable", ApiError(code, "x").isRetryable)
        }
    }

    @Test
    fun `field errors survive construction`() {
        val error = ApiError(
            ApiError.CODE_VALIDATION_FAILED,
            "check the fields",
            httpStatus = 422,
            fieldErrors = mapOf("email" to "Enter a valid email address."),
        )

        assertTrue(error.isValidation)
        assertEquals("Enter a valid email address.", error.fieldErrors["email"])
    }

    @Test
    fun `retry after is carried through for rate limiting`() {
        val error = ApiError(ApiError.CODE_RATE_LIMITED, "slow down", retryAfterSeconds = 42)

        assertEquals(42, error.retryAfterSeconds)
    }

    @Test
    fun `result map transforms success and preserves failure`() {
        val success: ApiResult<Int> = ApiResult.Success(21)
        val mapped = success.map { it * 2 }

        assertTrue(mapped is ApiResult.Success)
        assertEquals(42, mapped.dataOrNull())

        val failure: ApiResult<Int> = ApiResult.Failure(ApiError("X", "boom"))
        val mappedFailure = failure.map { it * 2 }

        assertTrue(mappedFailure is ApiResult.Failure)
        assertEquals("X", mappedFailure.errorOrNull()?.code)
    }

    @Test
    fun `result callbacks fire on the matching branch only`() {
        var successes = 0
        var failures = 0

        ApiResult.Success("ok").onSuccess { successes++ }.onFailure { failures++ }
        assertEquals(1, successes)
        assertEquals(0, failures)

        ApiResult.Failure(ApiError("X", "boom")).onSuccess { successes++ }.onFailure { failures++ }
        assertEquals(1, successes)
        assertEquals(1, failures)
    }
}

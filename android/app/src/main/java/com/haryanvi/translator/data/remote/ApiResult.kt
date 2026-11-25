package com.haryanvi.translator.data.remote

import kotlinx.serialization.json.JsonObject

/**
 * Outcome of an API call. Nothing throws across the repository boundary —
 * callers always get one of these two cases.
 */
sealed interface ApiResult<out T> {

    data class Success<out T>(
        val data: T,
        val meta: JsonObject? = null,
    ) : ApiResult<T>

    data class Failure(val error: ApiError) : ApiResult<Nothing>

    val isSuccess: Boolean
        get() = this is Success

    fun dataOrNull(): T? = (this as? Success)?.data

    fun errorOrNull(): ApiError? = (this as? Failure)?.error
}

/*
 * Transformations live as extensions rather than interface members so they
 * can be inlined — an interface method cannot be.
 */

inline fun <T, R> ApiResult<T>.map(transform: (T) -> R): ApiResult<R> = when (this) {
    is ApiResult.Success -> ApiResult.Success(transform(data), meta)
    is ApiResult.Failure -> this
}

inline fun <T> ApiResult<T>.onSuccess(action: (T) -> Unit): ApiResult<T> {
    if (this is ApiResult.Success) action(data)

    return this
}

inline fun <T> ApiResult<T>.onFailure(action: (ApiError) -> Unit): ApiResult<T> {
    if (this is ApiResult.Failure) action(error)

    return this
}

package com.haryanvi.translator.data.remote

import com.haryanvi.translator.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.DeserializationStrategy
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.net.SocketTimeoutException
import java.net.URLEncoder
import java.net.UnknownHostException
import java.util.concurrent.TimeUnit
import javax.net.ssl.SSLException

/**
 * HTTP layer for the Haryanvi AI REST API.
 *
 * Hand-rolled on OkHttp rather than Retrofit: the API uses one consistent
 * envelope, and unwrapping it in a single place means every failure — HTTP,
 * transport or application-level — reaches the repositories as a typed
 * [ApiError] instead of an exception.
 *
 * The base URL and bearer token are read per request through the supplied
 * lambdas, so changing server or signing out takes effect immediately.
 */
class ApiClient(
    private val baseUrlProvider: suspend () -> String,
    private val tokenProvider: suspend () -> String?,
    private val onUnauthorized: suspend () -> Unit,
) {
    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        explicitNulls = false
        encodeDefaults = true
        coerceInputValues = true
    }

    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        // Translation waits on an upstream AI provider, so the read timeout
        // must be generous — but never unbounded.
        .readTimeout(70, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()

    /* ----------------------------------------------------------------
     | Verbs
     * -------------------------------------------------------------- */

    suspend fun <T> get(
        path: String,
        serializer: DeserializationStrategy<T>,
        query: Map<String, String> = emptyMap(),
        authenticated: Boolean = true,
    ): ApiResult<T> = execute("GET", path, null, serializer, query, authenticated)

    suspend fun <T> post(
        path: String,
        body: JsonObject?,
        serializer: DeserializationStrategy<T>,
        authenticated: Boolean = true,
    ): ApiResult<T> = execute("POST", path, body, serializer, emptyMap(), authenticated)

    suspend fun <T> patch(
        path: String,
        body: JsonObject?,
        serializer: DeserializationStrategy<T>,
        authenticated: Boolean = true,
    ): ApiResult<T> = execute("PATCH", path, body, serializer, emptyMap(), authenticated)

    suspend fun <T> delete(
        path: String,
        serializer: DeserializationStrategy<T>,
        body: JsonObject? = null,
        authenticated: Boolean = true,
    ): ApiResult<T> = execute("DELETE", path, body, serializer, emptyMap(), authenticated)

    /* ----------------------------------------------------------------
     | Execution
     * -------------------------------------------------------------- */

    private suspend fun <T> execute(
        method: String,
        path: String,
        body: JsonObject?,
        serializer: DeserializationStrategy<T>,
        query: Map<String, String>,
        authenticated: Boolean,
    ): ApiResult<T> = withContext(Dispatchers.IO) {
        val baseUrl = baseUrlProvider().trim().trimEnd('/')

        if (baseUrl.isBlank()) {
            return@withContext ApiResult.Failure(ApiError.noServer())
        }

        if (!BuildConfig.ALLOW_CLEARTEXT && !baseUrl.startsWith("https://", ignoreCase = true)) {
            return@withContext ApiResult.Failure(
                ApiError(ApiError.CODE_INSECURE_SERVER, "The server address must use HTTPS.")
            )
        }

        val builder = Request.Builder()
            .url(baseUrl + path + encodeQuery(query))
            .header("Accept", "application/json")
            .header("X-Client", "android/${BuildConfig.VERSION_NAME}")

        if (authenticated) {
            val token = tokenProvider()
            if (token.isNullOrBlank()) {
                return@withContext ApiResult.Failure(
                    ApiError(ApiError.CODE_UNAUTHORIZED, "You need to sign in first.", 401)
                )
            }
            builder.header("Authorization", "Bearer $token")
        }

        val payload = body?.toString()?.toRequestBody(JSON_MEDIA_TYPE)

        when (method) {
            "GET" -> builder.get()
            "POST" -> builder.post(payload ?: EMPTY_BODY)
            "PATCH" -> builder.patch(payload ?: EMPTY_BODY)
            "DELETE" -> if (payload != null) builder.delete(payload) else builder.delete()
            else -> builder.method(method, payload)
        }

        try {
            client.newCall(builder.build()).execute().use { response ->
                val text = response.body?.string().orEmpty()
                val result = parse(response.code, text, serializer)

                // A rejected token clears local credentials once, centrally,
                // rather than in every calling screen.
                if (authenticated && result is ApiResult.Failure && result.error.isAuthFailure) {
                    onUnauthorized()
                }

                result
            }
        } catch (e: SocketTimeoutException) {
            ApiResult.Failure(ApiError.timeout("The server took too long to respond. Please try again."))
        } catch (e: UnknownHostException) {
            ApiResult.Failure(
                ApiError.network(
                    "Could not reach the server. Check your connection and the server address."
                )
            )
        } catch (e: SSLException) {
            ApiResult.Failure(
                ApiError(
                    ApiError.CODE_INSECURE_SERVER,
                    "The secure connection failed. Check that the server's SSL certificate is valid.",
                )
            )
        } catch (e: IOException) {
            ApiResult.Failure(ApiError.network(e.message ?: "Network error. Please try again."))
        }
    }

    /* ----------------------------------------------------------------
     | Envelope
     * -------------------------------------------------------------- */

    private fun <T> parse(
        httpStatus: Int,
        text: String,
        serializer: DeserializationStrategy<T>,
    ): ApiResult<T> {
        if (text.isBlank()) {
            return if (httpStatus in 200..299) {
                // 204-style success for calls whose payload we ignore.
                decode(serializer, JsonObject(emptyMap()))
            } else {
                ApiResult.Failure(
                    ApiError(
                        ApiError.CODE_BAD_RESPONSE,
                        "The server returned HTTP $httpStatus with no body.",
                        httpStatus,
                    )
                )
            }
        }

        val root = runCatching { json.parseToJsonElement(text).jsonObject }.getOrNull()
            ?: return ApiResult.Failure(
                ApiError(
                    ApiError.CODE_BAD_RESPONSE,
                    "The server did not return valid JSON. Check that the address points at a " +
                        "Haryanvi AI installation.",
                    httpStatus,
                )
            )

        val success = root["success"]?.jsonPrimitive?.booleanOrNull ?: false

        if (!success) {
            return ApiResult.Failure(readError(root, httpStatus))
        }

        val data = root["data"] ?: JsonObject(emptyMap())

        return decode(serializer, data, root["meta"] as? JsonObject)
    }

    private fun <T> decode(
        serializer: DeserializationStrategy<T>,
        element: kotlinx.serialization.json.JsonElement,
        meta: JsonObject? = null,
    ): ApiResult<T> = runCatching { json.decodeFromJsonElement(serializer, element) }
        .fold(
            onSuccess = { ApiResult.Success(it, meta) },
            onFailure = { throwable ->
                ApiResult.Failure(
                    ApiError.badResponse(
                        "The server's response could not be read: ${throwable.message ?: "unexpected shape"}"
                    )
                )
            },
        )

    private fun readError(root: JsonObject, httpStatus: Int): ApiError {
        val error = root["error"] as? JsonObject

        val details = error?.get("details") as? JsonObject

        val fieldErrors = (details?.get("fields") as? JsonObject)
            ?.mapValues { (_, value) -> (value as? JsonPrimitive)?.contentOrNull.orEmpty() }
            ?.filterValues { it.isNotBlank() }
            ?: emptyMap()

        return ApiError(
            code = error?.get("code")?.jsonPrimitive?.contentOrNull ?: ApiError.CODE_SERVER_ERROR,
            message = error?.get("message")?.jsonPrimitive?.contentOrNull
                ?: "The server reported an error (HTTP $httpStatus).",
            httpStatus = httpStatus,
            fieldErrors = fieldErrors,
            retryAfterSeconds = details?.get("retry_after")?.jsonPrimitive?.intOrNull,
        )
    }

    private fun encodeQuery(query: Map<String, String>): String {
        if (query.isEmpty()) return ""

        return query.entries.joinToString(prefix = "?", separator = "&") { (key, value) ->
            "${URLEncoder.encode(key, "UTF-8")}=${URLEncoder.encode(value, "UTF-8")}"
        }
    }

    private companion object {
        val JSON_MEDIA_TYPE = "application/json; charset=utf-8".toMediaType()
        val EMPTY_BODY = "{}".toRequestBody(JSON_MEDIA_TYPE)
    }
}

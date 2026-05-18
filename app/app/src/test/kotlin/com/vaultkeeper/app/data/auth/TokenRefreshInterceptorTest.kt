package com.vaultkeeper.app.data.auth

import com.vaultkeeper.app.data.api.AuthRefreshApi
import com.vaultkeeper.app.data.api.dto.RefreshResponse
import io.mockk.every
import io.mockk.just
import io.mockk.mockk
import io.mockk.runs
import io.mockk.slot
import io.mockk.verify
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory

class TokenRefreshInterceptorTest {

    private lateinit var server: MockWebServer
    private lateinit var unauthEvent: UnauthenticatedEvent
    private lateinit var authRefreshApi: AuthRefreshApi
    private lateinit var tokenStore: TokenStore

    private lateinit var client: OkHttpClient

    @Before
    fun setUp() {
        server = MockWebServer()
        server.start()

        unauthEvent = UnauthenticatedEvent()

        val json = Json { ignoreUnknownKeys = true }
        val retrofit = Retrofit.Builder()
            .baseUrl(server.url("/"))
            .client(OkHttpClient())
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
        authRefreshApi = retrofit.create(AuthRefreshApi::class.java)

        tokenStore = mockk<TokenStore>()

        val refreshInterceptor = TokenRefreshInterceptor(tokenStore, unauthEvent, authRefreshApi)
        client = OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor(tokenStore))
            .addInterceptor(refreshInterceptor)
            .build()
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `200 response passes through unchanged`() {
        every { tokenStore.token } returns "some-token"

        server.enqueue(MockResponse().setResponseCode(200).setBody("{}"))

        val response = get("/api/decks")

        assertEquals(200, response.code)
        assertEquals(1, server.requestCount)
    }

    @Test
    fun `401 triggers refresh and retries with new token`() {
        var storedToken = "old-token"
        every { tokenStore.token } answers { storedToken }
        every { tokenStore.token = any() } answers { storedToken = firstArg() }
        every { tokenStore.clear() } just runs

        server.enqueue(MockResponse().setResponseCode(401))
        server.enqueue(
            MockResponse().setResponseCode(200).setBody(
                """{"access_token":"new-token","token_type":"bearer","expires_in":3600}"""
            )
        )
        server.enqueue(MockResponse().setResponseCode(200).setBody("{}"))

        val response = get("/api/decks")

        assertEquals(200, response.code)
        assertEquals(3, server.requestCount)
        assertEquals("new-token", storedToken)

        server.takeRequest() // original → 401
        server.takeRequest() // POST /auth/refresh
        val retry = server.takeRequest()
        assertEquals("Bearer new-token", retry.getHeader("Authorization"))
    }

    @Test
    fun `401 with failed refresh emits unauthenticated event`() = runTest {
        every { tokenStore.token } returns "old-token"
        every { tokenStore.clear() } just runs

        val events = mutableListOf<Unit>()
        val job = launch { unauthEvent.flow.collect { events.add(it) } }

        server.enqueue(MockResponse().setResponseCode(401))
        server.enqueue(MockResponse().setResponseCode(401)) // refresh also fails
        server.enqueue(MockResponse().setResponseCode(401)) // unauthenticated retry

        get("/api/decks")

        delay(100)
        assertEquals(1, events.size)
        verify { tokenStore.clear() }
        job.cancel()
    }

    @Test
    fun `401 on refresh endpoint does not loop and emits unauthenticated event`() = runTest {
        every { tokenStore.token } returns "old-token"

        val events = mutableListOf<Unit>()
        val job = launch { unauthEvent.flow.collect { events.add(it) } }

        server.enqueue(MockResponse().setResponseCode(401))

        get("/auth/refresh")

        delay(100)
        assertEquals(1, events.size)
        assertEquals(1, server.requestCount)
        job.cancel()
    }

    @Test
    fun `401 with no stored token emits unauthenticated event without calling refresh`() = runTest {
        every { tokenStore.token } returns null

        val events = mutableListOf<Unit>()
        val job = launch { unauthEvent.flow.collect { events.add(it) } }

        server.enqueue(MockResponse().setResponseCode(401))
        server.enqueue(MockResponse().setResponseCode(401)) // unauthenticated retry

        get("/api/decks")

        delay(100)
        assertEquals(1, events.size)
        // Only the original request + one retry — no /auth/refresh call.
        val r1 = server.takeRequest()
        val r2 = server.takeRequest()
        assertEquals("/api/decks", r1.path)
        assertEquals("/api/decks", r2.path)
        job.cancel()
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private fun get(path: String) =
        client.newCall(Request.Builder().url(server.url(path)).build()).execute()
}

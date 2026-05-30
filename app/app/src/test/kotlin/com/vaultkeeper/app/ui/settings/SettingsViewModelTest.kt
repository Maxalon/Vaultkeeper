package com.vaultkeeper.app.ui.settings

import com.vaultkeeper.app.data.api.dto.PrivacySettingsDto
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.privacy.PrivacyRepository
import io.mockk.coAnswers
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.just
import io.mockk.mockk
import io.mockk.runs
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class SettingsViewModelTest {

    private val testDispatcher = UnconfinedTestDispatcher()
    private lateinit var privacy: PrivacyRepository
    private lateinit var auth: AuthRepository

    private val defaultDto = PrivacySettingsDto(
        collection_visibility = "friends",
        decks_visibility = "friends",
        discoverable = true,
    )

    @Before
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        privacy = mockk()
        auth = mockk()
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun vm() = SettingsViewModel(auth, privacy)

    @Test
    fun `load success populates state and clears loading flag`() = runTest {
        coEvery { privacy.get() } returns Result.success(defaultDto)

        val vm = vm()

        assertFalse(vm.state.value.loading)
        assertEquals("friends", vm.state.value.collectionVisibility)
        assertEquals("friends", vm.state.value.decksVisibility)
        assertEquals(true, vm.state.value.discoverable)
    }

    @Test
    fun `load failure clears loading flag and leaves fields at defaults`() = runTest {
        coEvery { privacy.get() } returns Result.failure(RuntimeException("network error"))

        val vm = vm()

        assertFalse(vm.state.value.loading)
        assertEquals("friends", vm.state.value.collectionVisibility)
        assertEquals("friends", vm.state.value.decksVisibility)
        assertEquals(true, vm.state.value.discoverable)
    }

    @Test
    fun `successful patch updates fields and sets success snackbar`() = runTest {
        coEvery { privacy.get() } returns Result.success(defaultDto)
        val updated = defaultDto.copy(collection_visibility = "private")
        coEvery { privacy.patch(any()) } returns Result.success(updated)

        val vm = vm()
        vm.setCollectionVisibility("private")

        assertFalse(vm.state.value.saving)
        assertEquals("private", vm.state.value.collectionVisibility)
        assertEquals("Privacy settings saved.", vm.state.value.snackbar)
    }

    @Test
    fun `failed patch sets error snackbar and clears saving flag`() = runTest {
        coEvery { privacy.get() } returns Result.success(defaultDto)
        coEvery { privacy.patch(any()) } returns Result.failure(RuntimeException("timeout"))

        val vm = vm()
        vm.setDiscoverable(false)

        assertFalse(vm.state.value.saving)
        assertEquals("timeout", vm.state.value.snackbar)
        // fields not updated on failure
        assertEquals(true, vm.state.value.discoverable)
    }

    @Test
    fun `snackbarShown clears snackbar`() = runTest {
        coEvery { privacy.get() } returns Result.success(defaultDto)
        coEvery { privacy.patch(any()) } returns Result.success(defaultDto)

        val vm = vm()
        vm.setCollectionVisibility("private")
        assertEquals("Privacy settings saved.", vm.state.value.snackbar)

        vm.snackbarShown()

        assertNull(vm.state.value.snackbar)
    }

    @Test
    fun `saving guard blocks concurrent patch calls`() = runTest {
        coEvery { privacy.get() } returns Result.success(defaultDto)
        val deferred = CompletableDeferred<Result<PrivacySettingsDto>>()
        coEvery { privacy.patch(any()) } coAnswers { deferred.await() }

        val vm = vm()
        // First call starts patch and suspends at deferred.await(); saving=true.
        vm.setCollectionVisibility("private")
        // Second call is rejected by the saving guard.
        vm.setDecksVisibility("private")

        deferred.complete(Result.success(defaultDto))

        coVerify(exactly = 1) { privacy.patch(any()) }
    }

    @Test
    fun `logout delegates to auth repository`() = runTest {
        coEvery { privacy.get() } returns Result.success(defaultDto)
        coEvery { auth.logout() } just runs

        val vm = vm()
        vm.logout()

        coVerify { auth.logout() }
    }
}

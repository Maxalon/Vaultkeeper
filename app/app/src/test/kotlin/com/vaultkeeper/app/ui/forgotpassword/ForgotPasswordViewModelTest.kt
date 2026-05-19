package com.vaultkeeper.app.ui.forgotpassword

import com.vaultkeeper.app.data.auth.AuthRepository
import io.mockk.coEvery
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ForgotPasswordViewModelTest {

    private val auth = mockk<AuthRepository>(relaxed = true)
    private lateinit var vm: ForgotPasswordViewModel

    @Before
    fun setUp() {
        Dispatchers.setMain(StandardTestDispatcher())
        vm = ForgotPasswordViewModel(auth)
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `submit with blank email sets error`() = runTest {
        vm.submit()
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with invalid email sets error`() = runTest {
        vm.onEmailChange("not-valid")
        vm.submit()
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `successful submit sets sent flag`() = runTest {
        coEvery { auth.forgotPassword(any()) } returns Result.success(Unit)
        vm.onEmailChange("user@example.com")
        vm.submit()
        advanceUntilIdle()
        assertTrue(vm.state.value.sent)
        assertNull(vm.state.value.error)
    }

    @Test
    fun `failed submit sets error message`() = runTest {
        coEvery { auth.forgotPassword(any()) } returns Result.failure(Exception("Not found"))
        vm.onEmailChange("user@example.com")
        vm.submit()
        advanceUntilIdle()
        assertNotNull(vm.state.value.error)
    }
}

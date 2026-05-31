package com.vaultkeeper.app.ui.resetpassword

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
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class ResetPasswordViewModelTest {

    private val auth = mockk<AuthRepository>(relaxed = true)
    private lateinit var vm: ResetPasswordViewModel

    @Before
    fun setUp() {
        Dispatchers.setMain(StandardTestDispatcher())
        vm = ResetPasswordViewModel(auth)
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun fillValid() {
        vm.onEmailChange("user@example.com")
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password1")
    }

    @Test
    fun `submit with blank email sets error`() = runTest {
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password1")
        vm.submit("tok") {}
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with invalid email sets error`() = runTest {
        vm.onEmailChange("not-an-email")
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password1")
        vm.submit("tok") {}
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with password shorter than 8 chars sets error`() = runTest {
        vm.onEmailChange("user@example.com")
        vm.onPasswordChange("short")
        vm.onConfirmPasswordChange("short")
        vm.submit("tok") {}
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with mismatched passwords sets error`() = runTest {
        vm.onEmailChange("user@example.com")
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password2")
        vm.submit("tok") {}
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `successful reset invokes onSuccess callback`() = runTest {
        coEvery { auth.resetPassword(any(), any(), any(), any()) } returns Result.success(Unit)
        fillValid()
        var called = false
        vm.submit("tok") { called = true }
        advanceUntilIdle()
        assert(called)
        assertNull(vm.state.value.error)
    }

    @Test
    fun `failed reset sets error message`() = runTest {
        coEvery { auth.resetPassword(any(), any(), any(), any()) } returns Result.failure(Exception("Token expired"))
        fillValid()
        vm.submit("tok") {}
        advanceUntilIdle()
        assertEquals("Token expired", vm.state.value.error)
    }
}

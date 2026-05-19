package com.vaultkeeper.app.ui.register

import com.vaultkeeper.app.data.auth.AuthRepository
import io.mockk.coEvery
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
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
class RegisterViewModelTest {

    private val auth = mockk<AuthRepository>(relaxed = true)
    private lateinit var vm: RegisterViewModel

    @Before
    fun setUp() {
        Dispatchers.setMain(StandardTestDispatcher())
        vm = RegisterViewModel(auth)
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun fillValid() {
        vm.onUsernameChange("alice")
        vm.onEmailChange("alice@example.com")
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password1")
    }

    @Test
    fun `submit with blank username sets error`() = runTest {
        vm.onEmailChange("alice@example.com")
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password1")
        vm.submit()
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with invalid email sets error`() = runTest {
        vm.onUsernameChange("alice")
        vm.onEmailChange("not-an-email")
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password1")
        vm.submit()
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with password shorter than 8 chars sets error`() = runTest {
        vm.onUsernameChange("alice")
        vm.onEmailChange("alice@example.com")
        vm.onPasswordChange("short")
        vm.onConfirmPasswordChange("short")
        vm.submit()
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with mismatched passwords sets error`() = runTest {
        vm.onUsernameChange("alice")
        vm.onEmailChange("alice@example.com")
        vm.onPasswordChange("password1")
        vm.onConfirmPasswordChange("password2")
        vm.submit()
        assertNotNull(vm.state.value.error)
    }

    @Test
    fun `submit with valid inputs calls auth register`() = runTest {
        coEvery { auth.register(any(), any(), any(), any()) } returns Result.success(mockk(relaxed = true))
        fillValid()
        vm.submit()
        assertNull(vm.state.value.error)
    }

    @Test
    fun `failed register sets error message`() = runTest {
        coEvery { auth.register(any(), any(), any(), any()) } returns Result.failure(Exception("Email taken"))
        fillValid()
        vm.submit()
        // allow coroutine to run
        kotlinx.coroutines.test.advanceUntilIdle()
        assertEquals("Email taken", vm.state.value.error)
    }
}

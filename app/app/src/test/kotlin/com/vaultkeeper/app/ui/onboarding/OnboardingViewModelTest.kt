package com.vaultkeeper.app.ui.onboarding

import com.vaultkeeper.app.data.auth.AuthRepository
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class OnboardingViewModelTest {

    private val dispatcher = StandardTestDispatcher()
    private lateinit var auth: AuthRepository
    private lateinit var vm: OnboardingViewModel

    @Before
    fun setUp() {
        Dispatchers.setMain(dispatcher)
        auth = mockk()
        vm = OnboardingViewModel(auth)
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `complete sets submitting true then false on success`() = runTest {
        coEvery { auth.completeOnboarding() } returns Result.success(Unit)

        vm.complete()
        assertTrue(vm.submitting.value)

        dispatcher.scheduler.advanceUntilIdle()
        assertFalse(vm.submitting.value)
    }

    @Test
    fun `complete resets submitting on API failure`() = runTest {
        coEvery { auth.completeOnboarding() } returns Result.failure(RuntimeException("network error"))

        vm.complete()
        assertTrue(vm.submitting.value)

        dispatcher.scheduler.advanceUntilIdle()
        assertFalse(vm.submitting.value)
    }

    @Test
    fun `second call while submitting is a no-op`() = runTest {
        coEvery { auth.completeOnboarding() } returns Result.success(Unit)

        vm.complete()
        assertTrue(vm.submitting.value)
        vm.complete() // should be ignored

        dispatcher.scheduler.advanceUntilIdle()
        coVerify(exactly = 1) { auth.completeOnboarding() }
    }

    @Test
    fun `complete emits to errors on API failure`() = runTest {
        coEvery { auth.completeOnboarding() } returns Result.failure(RuntimeException("network error"))

        val errors = mutableListOf<String>()
        val collector = launch { vm.errors.collect { errors.add(it) } }
        dispatcher.scheduler.advanceUntilIdle() // start the collector

        vm.complete()
        dispatcher.scheduler.advanceUntilIdle() // run the VM coroutine

        assertTrue(errors.isNotEmpty())
        collector.cancel()
    }
}

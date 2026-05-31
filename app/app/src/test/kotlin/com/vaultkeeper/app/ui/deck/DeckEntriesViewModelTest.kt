package com.vaultkeeper.app.ui.deck

import com.vaultkeeper.app.data.api.DeckApi
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.MoveZoneRequest
import io.mockk.coEvery
import io.mockk.coVerify
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class DeckEntriesViewModelTest {

    private val testDispatcher = StandardTestDispatcher()
    private val api = mockk<DeckApi>()

    private val entry1 = DeckEntryDto(id = 1L, scryfall_id = "abc", zone = "main", quantity = 1)
    private val entry2 = DeckEntryDto(id = 2L, scryfall_id = "def", zone = "main", quantity = 1)

    @Before
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        coEvery { api.getEntries(10L) } returns listOf(entry1, entry2)
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `loadEntries populates state on success`() = runTest {
        val vm = DeckEntriesViewModel(api, deckId = 10L)
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals(listOf(entry1, entry2), vm.state.value.entries)
        assertEquals(false, vm.state.value.isLoading)
        assertEquals(null, vm.state.value.error)
    }

    @Test
    fun `moveEntryToZone replaces only the targeted entry in state`() = runTest {
        val moved = entry1.copy(zone = "side")
        coEvery { api.updateEntry(10L, 1L, MoveZoneRequest("side")) } returns moved

        val vm = DeckEntriesViewModel(api, deckId = 10L)
        testDispatcher.scheduler.advanceUntilIdle()

        vm.moveEntryToZone(entryId = 1L, zone = DeckZone.SIDE)
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals(listOf(moved, entry2), vm.state.value.entries)
        coVerify(exactly = 1) { api.updateEntry(10L, 1L, MoveZoneRequest("side")) }
    }

    @Test
    fun `moveEntryToZone sets error state on API failure`() = runTest {
        coEvery { api.updateEntry(10L, 1L, any()) } throws RuntimeException("network error")

        val vm = DeckEntriesViewModel(api, deckId = 10L)
        testDispatcher.scheduler.advanceUntilIdle()

        vm.moveEntryToZone(entryId = 1L, zone = DeckZone.SIDE)
        testDispatcher.scheduler.advanceUntilIdle()

        assertEquals("network error", vm.state.value.error)
        // entries unchanged
        assertEquals(listOf(entry1, entry2), vm.state.value.entries)
    }

    @Test
    fun `DeckZone from parses all valid api values`() {
        assertEquals(DeckZone.MAIN, DeckZone.from("main"))
        assertEquals(DeckZone.SIDE, DeckZone.from("side"))
        assertEquals(DeckZone.MAYBE, DeckZone.from("maybe"))
    }

    @Test
    fun `DeckZone all contains exactly three zones`() {
        assertEquals(3, DeckZone.all.size)
    }
}

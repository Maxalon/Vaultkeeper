package com.vaultkeeper.app.ui.deck

import com.vaultkeeper.app.data.api.DeckEntryApi
import com.vaultkeeper.app.data.api.dto.DeckEntryDto
import com.vaultkeeper.app.data.api.dto.UpdateEntryRequest
import com.vaultkeeper.app.data.deck.DeckEntryRepository
import io.mockk.coEvery
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

class DeckEntryListViewModelTest {

    private val testDispatcher = StandardTestDispatcher()
    private lateinit var api: DeckEntryApi
    private lateinit var vm: DeckEntryListViewModel

    @Before
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
        api = mockk()
        vm = DeckEntryListViewModel(DeckEntryRepository(api))
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    @Test
    fun `moveToZone updates only the matching entry without reloading the full list`() = runTest {
        val entry1 = makeDeckEntry(id = 1, zone = "main")
        val entry2 = makeDeckEntry(id = 2, zone = "side")
        val entry1Moved = entry1.copy(zone = "side")

        coEvery { api.getEntries(42) } returns listOf(entry1, entry2)
        coEvery { api.updateEntry(42, 1, UpdateEntryRequest("side")) } returns entry1Moved

        vm.load(42)
        advanceUntilIdle()

        vm.moveToZone(deckId = 42, entryId = 1, newZone = "side")
        advanceUntilIdle()

        val entries = vm.entries.value
        assertEquals("side", entries.first { it.id == 1 }.zone)
        // entry2 is untouched
        assertEquals("side", entries.first { it.id == 2 }.zone)
    }

    @Test
    fun `moveToZone leaves other entries unchanged`() = runTest {
        val entry1 = makeDeckEntry(id = 1, zone = "main")
        val entry2 = makeDeckEntry(id = 2, zone = "main")
        val entry3 = makeDeckEntry(id = 3, zone = "maybe")
        val entry2Moved = entry2.copy(zone = "side")

        coEvery { api.getEntries(1) } returns listOf(entry1, entry2, entry3)
        coEvery { api.updateEntry(1, 2, UpdateEntryRequest("side")) } returns entry2Moved

        vm.load(1)
        advanceUntilIdle()
        vm.moveToZone(deckId = 1, entryId = 2, newZone = "side")
        advanceUntilIdle()

        val entries = vm.entries.value
        assertEquals("main", entries.first { it.id == 1 }.zone)
        assertEquals("side", entries.first { it.id == 2 }.zone)
        assertEquals("maybe", entries.first { it.id == 3 }.zone)
    }

    private fun makeDeckEntry(id: Int, zone: String) = DeckEntryDto(
        id = id,
        deckId = 1,
        scryfallId = "scryfall-$id",
        quantity = 1,
        zone = zone,
        isCommander = false,
        scryfallCard = null,
    )
}

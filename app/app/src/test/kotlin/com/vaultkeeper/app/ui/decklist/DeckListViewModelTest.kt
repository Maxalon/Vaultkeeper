package com.vaultkeeper.app.ui.decklist

import com.vaultkeeper.app.data.api.dto.DeckDto
import com.vaultkeeper.app.data.auth.AuthRepository
import com.vaultkeeper.app.data.repository.DeckRepository
import io.mockk.coEvery
import io.mockk.mockk
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.setMain
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

@OptIn(ExperimentalCoroutinesApi::class)
class DeckListViewModelTest {

    private val testDispatcher = UnconfinedTestDispatcher()
    private val deckRepo: DeckRepository = mockk()
    private val auth: AuthRepository = mockk()

    @Before
    fun setUp() {
        Dispatchers.setMain(testDispatcher)
    }

    @After
    fun tearDown() {
        Dispatchers.resetMain()
    }

    private fun deck(id: Long = 1L, name: String = "Test") =
        DeckDto(id = id, name = name, format = "commander")

    private fun viewModel() = DeckListViewModel(deckRepo, auth)

    @Test
    fun `load success populates decks and clears loading`() {
        val decks = listOf(deck(1L, "Alpha"), deck(2L, "Beta"))
        coEvery { deckRepo.listDecks() } returns Result.success(decks)

        val state = viewModel().state.value

        assertEquals(decks, state.decks)
        assertFalse(state.loading)
        assertNull(state.error)
    }

    @Test
    fun `load failure sets error and clears loading`() {
        coEvery { deckRepo.listDecks() } returns Result.failure(RuntimeException("network error"))

        val state = viewModel().state.value

        assertTrue(state.decks.isEmpty())
        assertFalse(state.loading)
        assertEquals("network error", state.error)
    }

    @Test
    fun `submitCreate success appends deck and closes sheet`() {
        val existing = deck(1L, "Existing")
        val created = deck(2L, "New Deck")
        coEvery { deckRepo.listDecks() } returns Result.success(listOf(existing))
        coEvery { deckRepo.createDeck("New Deck", "commander") } returns Result.success(created)

        val vm = viewModel()
        vm.openCreateSheet()
        vm.onCreateNameChange("New Deck")
        vm.submitCreate()

        val state = vm.state.value
        assertEquals(listOf(existing, created), state.decks)
        assertFalse(state.showCreateSheet)
        assertNull(state.createError)
    }

    @Test
    fun `confirmDelete success removes deck from list`() {
        val keep = deck(1L, "Keep")
        val remove = deck(2L, "Remove")
        coEvery { deckRepo.listDecks() } returns Result.success(listOf(keep, remove))
        coEvery { deckRepo.deleteDeck(2L) } returns Result.success(Unit)

        val vm = viewModel()
        vm.requestDelete(remove)
        vm.confirmDelete()

        val state = vm.state.value
        assertEquals(listOf(keep), state.decks)
        assertNull(state.pendingDelete)
    }

    @Test
    fun `confirmDelete with no pendingDelete is a no-op`() {
        val decks = listOf(deck(1L))
        coEvery { deckRepo.listDecks() } returns Result.success(decks)

        val vm = viewModel()
        vm.confirmDelete()

        val state = vm.state.value
        assertEquals(decks, state.decks)
        assertNull(state.pendingDelete)
    }
}

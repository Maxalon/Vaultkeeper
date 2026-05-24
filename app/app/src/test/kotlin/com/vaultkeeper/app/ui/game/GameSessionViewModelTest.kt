package com.vaultkeeper.app.ui.game

import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test

class GameSessionViewModelTest {

    private lateinit var vm: GameSessionViewModel

    @Before
    fun setUp() {
        vm = GameSessionViewModel()
        vm.reset(
            listOf(
                Player(id = 0, name = "Alice", lifeTotal = 40),
                Player(id = 1, name = "Bob",   lifeTotal = 40),
            ),
        )
    }

    @Test
    fun `applyKeypadAdjustment increases life total`() {
        vm.applyKeypadAdjustment(playerId = 0, delta = 47)

        assertEquals(87, playerLife(id = 0))
        assertEquals(40, playerLife(id = 1))
    }

    @Test
    fun `applyKeypadAdjustment decreases life total`() {
        vm.applyKeypadAdjustment(playerId = 1, delta = -20)

        assertEquals(40, playerLife(id = 0))
        assertEquals(20, playerLife(id = 1))
    }

    @Test
    fun `applyKeypadAdjustment records a single history entry`() {
        vm.applyKeypadAdjustment(playerId = 0, delta = 5)

        val history = vm.session.value.history
        assertEquals(1, history.size)
        assertEquals(LifeAdjustment(playerId = 0, delta = 5, previousLife = 40), history[0])
    }

    @Test
    fun `applyKeypadAdjustment allows life to go negative`() {
        vm.applyKeypadAdjustment(playerId = 0, delta = -50)

        assertEquals(-10, playerLife(id = 0))
    }

    @Test
    fun `undoLast restores previous life total and removes history entry`() {
        vm.applyKeypadAdjustment(playerId = 0, delta = 10)
        vm.undoLast()

        assertEquals(40, playerLife(id = 0))
        assertEquals(0, vm.session.value.history.size)
    }

    @Test
    fun `undoLast on empty history is a no-op`() {
        val before = vm.session.value
        vm.undoLast()

        assertEquals(before, vm.session.value)
    }

    @Test
    fun `undoLast only reverts most recent adjustment`() {
        vm.applyKeypadAdjustment(playerId = 0, delta = 10)
        vm.applyKeypadAdjustment(playerId = 0, delta = 5)
        vm.undoLast()

        assertEquals(50, playerLife(id = 0))
        assertEquals(1, vm.session.value.history.size)
    }

    @Test
    fun `unknown player id is ignored`() {
        val before = vm.session.value
        vm.applyKeypadAdjustment(playerId = 99, delta = 10)

        assertEquals(before, vm.session.value)
    }

    private fun playerLife(id: Int) =
        vm.session.value.players.first { it.id == id }.lifeTotal
}

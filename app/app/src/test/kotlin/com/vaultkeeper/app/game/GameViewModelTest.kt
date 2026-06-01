package com.vaultkeeper.app.game

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class GameViewModelTest {

    private lateinit var vm: GameViewModel
    private val alice = GamePlayer(id = "alice", name = "Alice")
    private val bob = GamePlayer(id = "bob", name = "Bob")
    private val carol = GamePlayer(id = "carol", name = "Carol")

    @Before
    fun setUp() {
        vm = GameViewModel()
        vm.startSession(listOf(alice, bob, carol))
    }

    @Test
    fun `incrementCommanderDamage increases damage by 1`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        assertEquals(1, vm.state.value.commanderDamageFrom("alice", "bob"))
    }

    @Test
    fun `incrementCommanderDamage accumulates across multiple calls`() {
        repeat(5) { vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob") }
        assertEquals(5, vm.state.value.commanderDamageFrom("alice", "bob"))
    }

    @Test
    fun `decrementCommanderDamage reduces damage by 1`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.decrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        assertEquals(1, vm.state.value.commanderDamageFrom("alice", "bob"))
    }

    @Test
    fun `decrementCommanderDamage does not go below 0`() {
        vm.decrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        assertEquals(0, vm.state.value.commanderDamageFrom("alice", "bob"))
    }

    @Test
    fun `commanderDamageReceived sums all sources`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "carol")
        assertEquals(3, vm.state.value.commanderDamageReceived("alice"))
    }

    @Test
    fun `undo reverts last damage change`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.undo()
        assertEquals(1, vm.state.value.commanderDamageFrom("alice", "bob"))
    }

    @Test
    fun `undo multiple times reverts to initial state`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "carol")
        vm.undo()
        vm.undo()
        assertEquals(0, vm.state.value.commanderDamageFrom("alice", "bob"))
        assertEquals(0, vm.state.value.commanderDamageFrom("alice", "carol"))
    }

    @Test
    fun `undo when stack is empty is a no-op`() {
        vm.undo() // should not throw
        assertEquals(0, vm.state.value.commanderDamageReceived("alice"))
    }

    @Test
    fun `incrementCommanderDamage adds history entry`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        val history = vm.state.value.history
        assertEquals(1, history.size)
        assertTrue(history[0].description.contains("Bob"))
        assertTrue(history[0].description.contains("Alice"))
    }

    @Test
    fun `decrementCommanderDamage adds history entry`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.decrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        assertEquals(2, vm.state.value.history.size)
    }

    @Test
    fun `undo restores previous history state`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.undo()
        assertEquals(1, vm.state.value.history.size)
    }

    @Test
    fun `toggleCommanderDamageRow expands then collapses`() {
        assertFalse("alice" in vm.state.value.expandedTiles)
        vm.toggleCommanderDamageRow("alice")
        assertTrue("alice" in vm.state.value.expandedTiles)
        vm.toggleCommanderDamageRow("alice")
        assertFalse("alice" in vm.state.value.expandedTiles)
    }

    @Test
    fun `damage changes on different players are independent`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.incrementCommanderDamage(receivingId = "bob", sourceId = "alice")
        vm.incrementCommanderDamage(receivingId = "bob", sourceId = "alice")
        assertEquals(1, vm.state.value.commanderDamageFrom("alice", "bob"))
        assertEquals(2, vm.state.value.commanderDamageFrom("bob", "alice"))
    }

    @Test
    fun `startSession resets all state`() {
        vm.incrementCommanderDamage(receivingId = "alice", sourceId = "bob")
        vm.toggleCommanderDamageRow("alice")
        vm.startSession(listOf(alice, bob))
        assertEquals(0, vm.state.value.commanderDamageReceived("alice"))
        assertTrue(vm.state.value.expandedTiles.isEmpty())
        assertTrue(vm.state.value.history.isEmpty())
    }
}

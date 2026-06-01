import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useGameStore } from '../game.js'

beforeEach(() => {
  setActivePinia(createPinia())
})

function twoPlayerGame(life = 40) {
  const game = useGameStore()
  game.configure({
    count: 2,
    life,
    seatConfig: [
      { name: 'P1', deckId: null, life },
      { name: 'P2', deckId: null, life },
    ],
  })
  return game
}

describe('game store', () => {
  it('starts with no active session', () => {
    const game = useGameStore()
    expect(game.playerCount).toBeNull()
    expect(game.seats).toHaveLength(0)
  })

  it('configure stores count, life, and seat config', () => {
    const game = useGameStore()
    game.configure({
      count: 3,
      life: 40,
      seatConfig: [
        { name: 'Alice', deckId: null, life: 40 },
        { name: 'Bob', deckId: 7, life: 40 },
        { name: 'Player 3', deckId: null, life: 40 },
      ],
    })
    expect(game.playerCount).toBe(3)
    expect(game.startingLife).toBe(40)
    expect(game.seats).toHaveLength(3)
    expect(game.seats[1].deckId).toBe(7)
  })

  it('configure copies seats rather than storing the reference', () => {
    const game = useGameStore()
    const src = [{ name: 'A', deckId: null, life: 20 }]
    game.configure({ count: 1, life: 20, seatConfig: src })
    src[0].name = 'Mutated'
    expect(game.seats[0].name).toBe('A')
  })

  it('reset clears all session state', () => {
    const game = twoPlayerGame()
    game.reset()
    expect(game.playerCount).toBeNull()
    expect(game.startingLife).toBe(40)
    expect(game.seats).toHaveLength(0)
  })

  // ── adjustLife ──────────────────────────────────────────────────
  it('adjustLife increments seat life', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(0, 1)
    expect(game.seats[0].life).toBe(41)
    expect(game.seats[1].life).toBe(40)
  })

  it('adjustLife decrements seat life', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(1, -1)
    expect(game.seats[1].life).toBe(39)
  })

  it('adjustLife allows life to go negative', () => {
    const game = twoPlayerGame(1)
    game.adjustLife(0, -5)
    expect(game.seats[0].life).toBe(-4)
  })

  it('adjustLife records entry in history', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(0, +5)
    expect(game.history).toHaveLength(1)
    expect(game.history[0]).toMatchObject({ seatIndex: 0, delta: 5, life: 45 })
    expect(typeof game.history[0].timestamp).toBe('number')
  })

  it('adjustLife records playerName and counterType in history', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(1, -3)
    expect(game.history[0].playerName).toBe('P2')
    expect(game.history[0].counterType).toBe('life')
  })

  it('adjustLife is a no-op for out-of-range index', () => {
    const game = twoPlayerGame(20)
    game.adjustLife(99, +1)
    expect(game.seats[0].life).toBe(20)
    expect(game.history).toHaveLength(0)
  })

  // ── undo ────────────────────────────────────────────────────────
  it('undo reverts the last life adjustment', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(0, +3)
    game.undo()
    expect(game.seats[0].life).toBe(40)
  })

  it('undo marks the reverted entry as undone in history', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(0, +1)
    game.adjustLife(0, +1)
    game.undo()
    expect(game.history).toHaveLength(2)
    expect(game.history[0].undone).toBeFalsy()
    expect(game.history[1].undone).toBe(true)
  })

  it('undo marks all entries as undone when full stack is exhausted', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(0, +1)
    game.adjustLife(1, -2)
    game.undo()
    game.undo()
    expect(game.history.every((e) => e.undone)).toBe(true)
    expect(game.undoStack).toHaveLength(0)
  })

  it('undo is a no-op when stack is empty', () => {
    const game = twoPlayerGame(40)
    game.undo()
    expect(game.seats[0].life).toBe(40)
  })

  it('multiple adjustments and full undo sequence', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(0, +1)
    game.adjustLife(0, +1)
    game.adjustLife(1, -5)
    game.undo()
    expect(game.seats[1].life).toBe(40)
    game.undo()
    expect(game.seats[0].life).toBe(41)
    game.undo()
    expect(game.seats[0].life).toBe(40)
  })

  it('configure clears history and undo stack from a previous game', () => {
    const game = twoPlayerGame(40)
    game.adjustLife(0, +1)
    game.configure({
      count: 2,
      life: 20,
      seatConfig: [
        { name: 'A', deckId: null, life: 20 },
        { name: 'B', deckId: null, life: 20 },
      ],
    })
    expect(game.history).toHaveLength(0)
    game.undo()
    expect(game.seats[0].life).toBe(20)
  })
})

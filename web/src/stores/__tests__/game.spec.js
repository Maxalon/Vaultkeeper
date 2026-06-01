import { describe, it, expect, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useGameStore } from '../game.js'

beforeEach(() => {
  setActivePinia(createPinia())
})

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
    const game = useGameStore()
    game.configure({
      count: 2,
      life: 20,
      seatConfig: [
        { name: 'P1', deckId: null, life: 20 },
        { name: 'P2', deckId: null, life: 20 },
      ],
    })
    game.reset()
    expect(game.playerCount).toBeNull()
    expect(game.startingLife).toBe(40)
    expect(game.seats).toHaveLength(0)
  })

  it('configure initialises poison to 0 for every seat', () => {
    const game = useGameStore()
    game.configure({
      count: 2,
      life: 40,
      seatConfig: [
        { name: 'P1', deckId: null, life: 40 },
        { name: 'P2', deckId: null, life: 40 },
      ],
    })
    expect(game.seats[0].poison).toBe(0)
    expect(game.seats[1].poison).toBe(0)
  })

  it('adjustPoison increments and clamps at 0', () => {
    const game = useGameStore()
    game.configure({
      count: 1,
      life: 40,
      seatConfig: [{ name: 'P1', deckId: null, life: 40 }],
    })
    game.adjustPoison(0, 3)
    expect(game.seats[0].poison).toBe(3)
    game.adjustPoison(0, -10)
    expect(game.seats[0].poison).toBe(0)
  })

  it('adjustPoison records entries in history', () => {
    const game = useGameStore()
    game.configure({
      count: 1,
      life: 40,
      seatConfig: [{ name: 'P1', deckId: null, life: 40 }],
    })
    game.adjustPoison(0, 1)
    game.adjustPoison(0, 1)
    expect(game.history).toHaveLength(2)
  })

  it('undo reverses the last poison adjustment', () => {
    const game = useGameStore()
    game.configure({
      count: 1,
      life: 40,
      seatConfig: [{ name: 'P1', deckId: null, life: 40 }],
    })
    game.adjustPoison(0, 5)
    game.undo()
    expect(game.seats[0].poison).toBe(0)
    expect(game.history).toHaveLength(0)
  })

  it('undo is a no-op when history is empty', () => {
    const game = useGameStore()
    game.configure({
      count: 1,
      life: 40,
      seatConfig: [{ name: 'P1', deckId: null, life: 40 }],
    })
    expect(() => game.undo()).not.toThrow()
  })

  it('adjustPoison is a no-op and does not push history when clamped at 0', () => {
    const game = useGameStore()
    game.configure({
      count: 1,
      life: 40,
      seatConfig: [{ name: 'P1', deckId: null, life: 40 }],
    })
    game.adjustPoison(0, -1)
    expect(game.seats[0].poison).toBe(0)
    expect(game.history).toHaveLength(0)
  })

  it('reset clears history', () => {
    const game = useGameStore()
    game.configure({
      count: 1,
      life: 40,
      seatConfig: [{ name: 'P1', deckId: null, life: 40 }],
    })
    game.adjustPoison(0, 3)
    game.reset()
    expect(game.history).toHaveLength(0)
  })
})

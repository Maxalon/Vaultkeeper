import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useGameStore = defineStore('game', () => {
  const playerCount = ref(null)
  const startingLife = ref(40)
  const seats = ref([])

  // Each entry: { seatIndex, playerName, counterType, delta, life, timestamp, undone? }
  // or: { type: 'roll', playerName, label, result, timestamp }
  const history = ref([])
  // Each entry: snapshot of seats array for full undo
  const undoStack = ref([])

  // Index of the last-interacted seat; null means no specific player in focus.
  const focusedSeat = ref(null)

  function configure({ count, life, seatConfig }) {
    playerCount.value = count
    startingLife.value = life
    seats.value = seatConfig.map((s) => ({ ...s }))
    history.value = []
    undoStack.value = []
    focusedSeat.value = null
  }

  function reset() {
    playerCount.value = null
    startingLife.value = 40
    seats.value = []
    history.value = []
    undoStack.value = []
    focusedSeat.value = null
  }

  function adjustLife(seatIndex, delta) {
    if (seatIndex < 0 || seatIndex >= seats.value.length) return
    focusedSeat.value = seatIndex
    undoStack.value.push(seats.value.map((s) => ({ ...s })))
    seats.value[seatIndex].life += delta
    history.value.push({
      seatIndex,
      playerName: seats.value[seatIndex].name,
      counterType: 'life',
      delta,
      life: seats.value[seatIndex].life,
      timestamp: Date.now(),
    })
  }

  function undo() {
    if (!undoStack.value.length) return
    const snapshot = undoStack.value.pop()
    const idx = history.value.findLastIndex((e) => !e.undone)
    if (idx >= 0) history.value[idx] = { ...history.value[idx], undone: true }
    seats.value = snapshot
  }

  function _rollerPlayerName() {
    if (focusedSeat.value !== null && seats.value[focusedSeat.value]) {
      return seats.value[focusedSeat.value].name
    }
    return 'Table'
  }

  // faces: 4 | 6 | 8 | 10 | 12 | 20
  // Returns the rolled number.
  function rollDice(faces) {
    const result = Math.floor(Math.random() * faces) + 1
    history.value.push({
      type: 'roll',
      playerName: _rollerPlayerName(),
      label: `d${faces}`,
      result,
      timestamp: Date.now(),
    })
    return result
  }

  // Returns 'Heads' or 'Tails'.
  function flipCoin() {
    const result = Math.random() < 0.5 ? 'Heads' : 'Tails'
    history.value.push({
      type: 'roll',
      playerName: _rollerPlayerName(),
      label: 'coin',
      result,
      timestamp: Date.now(),
    })
    return result
  }

  return {
    playerCount, startingLife, seats, history, undoStack, focusedSeat,
    configure, reset, adjustLife, undo, rollDice, flipCoin,
  }
})

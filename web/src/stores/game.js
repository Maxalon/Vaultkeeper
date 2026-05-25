import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useGameStore = defineStore('game', () => {
  const playerCount = ref(null)
  const startingLife = ref(40)
  const seats = ref([])

  // Each entry: { seatIndex, delta, life, timestamp }
  const history = ref([])
  // Each entry: snapshot of seats array for full undo
  const undoStack = ref([])

  function configure({ count, life, seatConfig }) {
    playerCount.value = count
    startingLife.value = life
    seats.value = seatConfig.map((s) => ({ ...s }))
    history.value = []
    undoStack.value = []
  }

  function reset() {
    playerCount.value = null
    startingLife.value = 40
    seats.value = []
    history.value = []
    undoStack.value = []
  }

  function adjustLife(seatIndex, delta) {
    if (seatIndex < 0 || seatIndex >= seats.value.length) return
    undoStack.value.push(seats.value.map((s) => ({ ...s })))
    seats.value[seatIndex].life += delta
    history.value.push({
      seatIndex,
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

  return { playerCount, startingLife, seats, history, undoStack, configure, reset, adjustLife, undo }
})

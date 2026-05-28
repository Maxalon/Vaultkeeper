import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useGameStore = defineStore('game', () => {
  const playerCount = ref(null)
  const startingLife = ref(40)
  const seats = ref([])
  const history = ref([])

  function configure({ count, life, seatConfig }) {
    playerCount.value = count
    startingLife.value = life
    seats.value = seatConfig.map((s) => ({ poison: 0, ...s }))
    history.value = []
  }

  function adjustPoison(seatIndex, delta) {
    const current = seats.value[seatIndex].poison
    const next = Math.max(0, current + delta)
    if (next === current) return
    history.value.push({ seatIndex, field: 'poison', before: current, after: next })
    seats.value[seatIndex].poison = next
  }

  function undo() {
    const entry = history.value.pop()
    if (!entry) return
    seats.value[entry.seatIndex][entry.field] = entry.before
  }

  function reset() {
    playerCount.value = null
    startingLife.value = 40
    seats.value = []
    history.value = []
  }

  return { playerCount, startingLife, seats, history, configure, adjustPoison, undo, reset }
})

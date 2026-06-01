import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useGameStore = defineStore('game', () => {
  const playerCount = ref(null)
  const startingLife = ref(40)
  const seats = ref([])

  function configure({ count, life, seatConfig }) {
    playerCount.value = count
    startingLife.value = life
    seats.value = seatConfig.map((s) => ({ ...s }))
  }

  function reset() {
    playerCount.value = null
    startingLife.value = 40
    seats.value = []
  }

  return { playerCount, startingLife, seats, configure, reset }
})

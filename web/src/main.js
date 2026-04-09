import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './style.css'

// Set the global --card-width before any component mounts so layout
// calculations (column count, strip-expanded height, hover offset) all use
// the same source of truth. On HiDPI / 4K screens we display larger cards
// because the user has more pixels to spend on each one.
function applyCardWidth() {
  const dpr = window.devicePixelRatio || 1
  const screenW = (window.screen && window.screen.width) || 0
  const isHiDpi = dpr >= 2 || screenW >= 2560
  const width = isHiDpi ? 220 : 146
  document.documentElement.style.setProperty('--card-width', `${width}px`)
}
applyCardWidth()

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')

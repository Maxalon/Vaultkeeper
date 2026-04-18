import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useSettingsStore } from './stores/settings'
import './style.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)

// Hydrate persisted user settings before mount so density / display-mode
// are applied without a flash of the defaults.
const settings = useSettingsStore()
settings.hydrate()
document.documentElement.setAttribute('data-density', settings.density)

app.mount('#app')

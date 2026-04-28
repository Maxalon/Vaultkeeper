import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useSettingsStore } from './stores/settings'

// Self-hosted fonts. Inter Tight — UI sans; Newsreader — display serif
// (includes italics used on the login title). JetBrains Mono — kbd/pip/badge.
import '@fontsource/inter-tight/400.css'
import '@fontsource/inter-tight/500.css'
import '@fontsource/inter-tight/600.css'
import '@fontsource/inter-tight/700.css'
import '@fontsource/newsreader/300.css'
import '@fontsource/newsreader/400.css'
import '@fontsource/newsreader/500.css'
import '@fontsource/newsreader/400-italic.css'
import '@fontsource/newsreader/500-italic.css'
import '@fontsource/jetbrains-mono/400.css'
import '@fontsource/jetbrains-mono/500.css'
import '@fontsource/jetbrains-mono/600.css'

import './style.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)

// Hydrate persisted user settings before mount so density / display-mode
// are applied without a flash of the defaults.
const settings = useSettingsStore()
settings.hydrate()
document.documentElement.setAttribute('data-density', settings.density)
// Apply persisted sidebar width as a CSS variable so the grid template
// reflects the user's choice on first paint (the default in style.css is
// 240px; persisted values override it here before mount).
document.documentElement.style.setProperty('--sidebar-width', `${settings.sidebarWidth}px`)

app.mount('#app')

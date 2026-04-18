import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import CollectionView from '../views/CollectionView.vue'

/**
 * Client-side JWT expiry check. Decodes the payload segment (no signature
 * verification — that's the server's job) and returns true if `exp` is
 * past or the token can't be parsed. Lets us short-circuit protected-route
 * navigation before the stale token fires a 401 against the backend.
 */
function isTokenExpired(token) {
  try {
    const payload = JSON.parse(atob(token.split('.')[1]))
    return !payload.exp || payload.exp * 1000 < Date.now()
  } catch {
    return true
  }
}

const routes = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('../views/RegisterView.vue'),
  },
  {
    path: '/collection',
    name: 'collection',
    component: CollectionView,
    meta: { requiresAuth: true },
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('../views/SettingsView.vue'),
    meta: { requiresAuth: true },
  },
  {
    path: '/',
    redirect: { name: 'collection' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  const tokenValid = token && !isTokenExpired(token)
  if (!tokenValid && token) {
    // Expired or malformed — clear it so the axios interceptor doesn't
    // also fire a second redirect once a request lands.
    localStorage.removeItem('token')
  }
  if (to.meta.requiresAuth && !tokenValid) {
    return { name: 'login' }
  }
  // If the user is already signed in, both /login and /register should bounce
  // them back into the app.
  if ((to.name === 'login' || to.name === 'register') && tokenValid) {
    return { name: 'collection' }
  }
})

export default router

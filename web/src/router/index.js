import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import CollectionView from '../views/CollectionView.vue'

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
  if (to.meta.requiresAuth && !token) {
    return { name: 'login' }
  }
  // If the user is already signed in, both /login and /register should bounce
  // them back into the app.
  if ((to.name === 'login' || to.name === 'register') && token) {
    return { name: 'collection' }
  }
})

export default router

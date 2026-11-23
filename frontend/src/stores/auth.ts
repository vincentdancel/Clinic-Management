import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, clearCsrfToken, prepareCsrfCookie } from '@/services/api'

export interface Permission {
  id: number
  name: string
  slug: string
}

export interface AuthUser {
  id: number
  name: string
  email: string
  is_active: boolean
  role: { id: number; name: string; slug: string; permissions: Permission[] } | null
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const initialized = ref(false)

  async function initialize(): Promise<void> {
    if (initialized.value) return
    try {
      const { data } = await api.get<{ user: AuthUser }>('/auth/me')
      user.value = data.user
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(email: string, password: string, remember: boolean): Promise<void> {
    await prepareCsrfCookie(true)
    const { data } = await api.post<{ user: AuthUser }>('/auth/login', { email, password, remember })
    user.value = data.user
    initialized.value = true
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/auth/logout')
    } finally {
      clearCsrfToken()
      user.value = null
      initialized.value = true
    }
  }

  async function refreshUser(): Promise<void> {
    const { data } = await api.get<{ user: AuthUser }>('/auth/me')
    user.value = data.user
  }

  function hasPermission(slug: string): boolean {
    return user.value?.role?.permissions.some((permission) => permission.slug === slug) ?? false
  }

  return { user, initialized, initialize, login, logout, refreshUser, hasPermission }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api, clearCsrfToken, prepareCsrfCookie } from '@/services/api'
import type { AuthUser } from '@/stores/auth'

export interface PatientPortalProfile {
  id: number
  patient_number: string
  first_name: string
  last_name: string
  full_name: string
  gender: string | null
  date_of_birth: string | null
  age: number | null
  phone: string | null
  email: string | null
  address: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  status: string
}

export const usePatientAuthStore = defineStore('patientAuth', () => {
  const user = ref<AuthUser | null>(null)
  const patient = ref<PatientPortalProfile | null>(null)
  const initialized = ref(false)

  async function initialize(): Promise<void> {
    if (initialized.value) return
    try {
      const { data } = await api.get<{ user: AuthUser; patient: PatientPortalProfile }>('/patient/me')
      user.value = data.user
      patient.value = data.patient
    } catch {
      user.value = null
      patient.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(email: string, password: string, remember: boolean): Promise<void> {
    await prepareCsrfCookie(true)
    const { data } = await api.post<{ user: AuthUser; patient: PatientPortalProfile }>('/patient/login', {
      email,
      password,
      remember,
    })
    user.value = data.user
    patient.value = data.patient
    initialized.value = true
  }

  async function logout(): Promise<void> {
    try {
      await api.post('/patient/logout')
    } finally {
      clearCsrfToken()
      user.value = null
      patient.value = null
      initialized.value = true
    }
  }

  async function refresh(): Promise<void> {
    const { data } = await api.get<{ user: AuthUser; patient: PatientPortalProfile }>('/patient/me')
    user.value = data.user
    patient.value = data.patient
  }

  function hasPermission(slug: string): boolean {
    return user.value?.role?.permissions.some((permission) => permission.slug === slug) ?? false
  }

  return { user, patient, initialized, initialize, login, logout, refresh, hasPermission }
})

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationsStore()
const router = useRouter()
const name = ref(auth.user?.name ?? '')
const email = ref(auth.user?.email ?? '')
const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  try {
    const payload: Record<string, string> = { name: name.value, email: email.value }
    if (password.value) {
      payload.current_password = currentPassword.value
      payload.password = password.value
      payload.password_confirmation = passwordConfirmation.value
    }
    const { data } = await api.put('/auth/profile', payload)
    auth.user = data.user
    currentPassword.value = password.value = passwordConfirmation.value = ''
    notifications.show('Your profile has been updated.', 'success')
  } catch (cause: unknown) {
    notifications.show(getApiErrorMessage(cause, 'Unable to update your profile.'), 'error')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="app-shell"><header class="topbar"><RouterLink to="/" class="auth-brand text-decoration-none"><span class="brand-mark">C</span><span>CLINIC <b>CARE</b></span></RouterLink>
    <v-btn to="/" variant="text" prepend-icon="mdi-arrow-left">Workspace</v-btn></header>
    <main class="workspace narrow"><div class="eyebrow">ACCOUNT SETTINGS</div><h1 class="text-h3 font-weight-bold mt-2">My profile</h1>
      <v-card class="feature-card pa-6 mt-7" rounded="xl" elevation="0"><v-form @submit.prevent="submit"><v-text-field v-model="name" label="Full name" variant="outlined" autocomplete="name" required />
        <v-text-field v-model="email" label="Email address" variant="outlined" type="email" autocomplete="email" required />
        <v-divider class="my-5" /><h2 class="text-h6 mb-1">Change password</h2><p class="text-body-2 text-medium-emphasis mb-5">Leave these fields empty to keep your current password.</p>
        <v-text-field v-model="currentPassword" label="Current password" variant="outlined" type="password" autocomplete="current-password" />
        <v-text-field v-model="password" label="New password" variant="outlined" type="password" autocomplete="new-password" />
        <v-text-field v-model="passwordConfirmation" label="Confirm new password" variant="outlined" type="password" autocomplete="new-password" />
        <div class="d-flex justify-end ga-3"><v-btn variant="text" @click="router.push('/')">Cancel</v-btn><v-btn type="submit" color="primary" rounded="lg" :loading="loading">Save changes</v-btn></div>
      </v-form></v-card>
    </main>
  </div>
</template>

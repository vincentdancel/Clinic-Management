<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationsStore()
const router = useRouter()

async function logout() {
  try {
    await auth.logout()
    notifications.show('You have been signed out.', 'success')
    await router.replace('/login')
  } catch {
    notifications.show('Could not sign out. Please try again.', 'error')
  }
}
</script>

<template>
  <div class="app-shell">
    <header class="topbar"><RouterLink to="/" class="auth-brand text-decoration-none"><span class="brand-mark">C</span><span>CLINIC <b>CARE</b></span></RouterLink>
      <div class="d-flex align-center ga-2"><v-btn v-if="auth.user?.role?.slug === 'administrator' && auth.hasPermission('users.manage')" to="/admin" variant="text" prepend-icon="mdi-account-cog-outline">User management</v-btn>
        <v-btn to="/profile" variant="text" prepend-icon="mdi-account-circle-outline">My profile</v-btn><v-btn variant="outlined" rounded="lg" @click="logout">Sign out</v-btn></div>
    </header>
    <main class="workspace"><div class="eyebrow">STAFF WORKSPACE</div><h1 class="text-h3 font-weight-bold mt-2">Good to see you, {{ auth.user?.name?.split(' ')[0] }}.</h1>
      <p class="text-medium-emphasis mt-3">You’re signed in to the Clinic Care management system.</p>
      <v-row class="mt-7" dense><v-col cols="12" md="6"><v-card class="feature-card pa-6" rounded="xl" elevation="0"><v-avatar color="primary" variant="tonal" size="48"><v-icon icon="mdi-shield-account-outline" /></v-avatar>
        <h2 class="text-h6 font-weight-bold mt-5">Your account</h2><p class="text-body-2 text-medium-emphasis mt-2">{{ auth.user?.email }}<br>{{ auth.user?.role?.name ?? 'No role assigned' }}</p>
        <v-btn to="/profile" color="primary" variant="tonal" class="mt-4" rounded="lg">Manage profile</v-btn></v-card></v-col>
        <v-col cols="12" md="6"><v-card class="feature-card pa-6" rounded="xl" elevation="0"><v-avatar color="secondary" variant="tonal" size="48"><v-icon icon="mdi-lock-check-outline" /></v-avatar>
          <h2 class="text-h6 font-weight-bold mt-5">Secure access</h2><p class="text-body-2 text-medium-emphasis mt-2">Your access is based on the role assigned by your clinic administrator.</p>
          <v-btn v-if="auth.user?.role?.slug === 'administrator' && auth.hasPermission('users.manage')" to="/admin" color="primary" variant="tonal" class="mt-4" rounded="lg">Manage team access</v-btn></v-card></v-col></v-row>
    </main>
  </div>
</template>

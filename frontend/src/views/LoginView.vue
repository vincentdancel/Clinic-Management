<script setup lang="ts">
import { getApiErrorMessage, getApiRoot } from '@/services/api'
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationsStore()
const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const remember = ref(true)
const loading = ref(false)
const googleLoading = ref(false)
const error = ref(typeof route.query.oauth_error === 'string' ? route.query.oauth_error : '')

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await auth.login(email.value, password.value, remember.value)
    notifications.show('Welcome back.', 'success')
    await router.replace(typeof route.query.redirect === 'string' ? route.query.redirect : '/')
  } catch (cause: unknown) {
    error.value = getApiErrorMessage(cause, 'Unable to sign in. Please try again.')
  } finally {
    loading.value = false
  }
}

function continueWithGoogle() {
  googleLoading.value = true
  error.value = ''
  window.location.assign(`${getApiRoot()}/auth/google/redirect`)
}
</script>

<template>
  <v-container class="auth-page fill-height" fluid>
    <v-row align="center" justify="center">
      <v-col cols="12" sm="9" md="6" lg="4">
        <div class="auth-brand mb-8"><span class="brand-mark">C</span><span>CLINIC <b>CARE</b></span></div>
        <v-card class="auth-card pa-7 pa-md-9" rounded="xl" elevation="0">
          <p class="eyebrow">STAFF PORTAL</p>
          <h1 class="text-h4 font-weight-bold mb-2">Welcome back</h1>
          <p class="text-medium-emphasis mb-7">Sign in to continue to your workspace.</p>
          <v-alert v-if="error" type="error" variant="tonal" class="mb-5" density="comfortable">{{ error }}</v-alert>
          <v-form @submit.prevent="submit">
            <v-text-field v-model="email" label="Email address" type="email" autocomplete="username" prepend-inner-icon="mdi-email-outline" variant="outlined" class="mb-2" required />
            <v-text-field v-model="password" label="Password" type="password" autocomplete="current-password" prepend-inner-icon="mdi-lock-outline" variant="outlined" required />
            <div class="d-flex align-center justify-space-between mb-5">
              <v-checkbox v-model="remember" label="Remember me" density="compact" hide-details color="primary" />
              <RouterLink class="text-decoration-none text-primary text-body-2 font-weight-medium" to="/forgot-password">Forgot password?</RouterLink>
            </div>
            <v-btn type="submit" color="primary" size="large" block rounded="lg" :loading="loading">Sign in</v-btn>
          </v-form>
          <div class="d-flex align-center my-5">
            <v-divider />
            <span class="px-3 text-caption text-medium-emphasis">OR</span>
            <v-divider />
          </div>
          <v-btn
            variant="outlined"
            size="large"
            block
            rounded="lg"
            :loading="googleLoading"
            :disabled="loading"
            @click="continueWithGoogle"
          >
            <template #prepend>
              <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17Z" />
                <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.12 0-5.77-2.11-6.72-4.96H1.29v3.14C3.26 21.3 7.31 24 12 24Z" />
                <path fill="#FBBC05" d="M5.28 14.24c-.24-.72-.38-1.49-.38-2.24s.14-1.52.38-2.24V6.62H1.29C.47 8.24 0 10.06 0 12s.47 3.76 1.29 5.38l3.99-3.14Z" />
                <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.31 0 3.26 2.7 1.29 6.62l3.99 3.14c.95-2.85 3.6-4.96 6.72-4.96Z" />
              </svg>
            </template>
            Continue with Google
          </v-btn>
          <p class="text-caption text-medium-emphasis mt-6 text-center">Accounts are created by your clinic administrator.</p>
          <p class="text-caption text-center mt-2">
            <RouterLink class="text-decoration-none text-primary font-weight-medium" to="/patient/login">Patient Portal sign in</RouterLink>
          </p>
        </v-card>
        <p class="auth-foot text-center mt-7">Secure access for your care team</p>
      </v-col>
    </v-row>
  </v-container>
</template>

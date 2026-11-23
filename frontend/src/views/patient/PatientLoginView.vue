<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getApiErrorMessage } from '@/services/api'
import { usePatientAuthStore } from '@/stores/patientAuth'
import { useNotificationsStore } from '@/stores/notifications'

const patientAuth = usePatientAuthStore()
const notifications = useNotificationsStore()
const route = useRoute()
const router = useRouter()

const email = ref('')
const password = ref('')
const remember = ref(true)
const loading = ref(false)
const error = ref('')

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await patientAuth.login(email.value, password.value, remember.value)
    notifications.show('Welcome to your Patient Portal.', 'success')
    await router.replace(typeof route.query.redirect === 'string' ? route.query.redirect : '/patient/dashboard')
  } catch (cause: unknown) {
    error.value = getApiErrorMessage(cause, 'Unable to sign in. Please check your credentials.')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-container class="auth-page fill-height" fluid>
    <v-row align="center" justify="center">
      <v-col cols="12" sm="9" md="6" lg="4">
        <div class="auth-brand mb-8"><span class="brand-mark">C</span><span>CLINIC <b>CARE</b></span></div>
        <v-card class="auth-card pa-7 pa-md-9" rounded="xl" elevation="0">
          <p class="eyebrow">PATIENT PORTAL</p>
          <h1 class="text-h4 font-weight-bold mb-2">Patient sign in</h1>
          <p class="text-medium-emphasis mb-7">Access your appointments, released medical records, prescriptions, and billing.</p>
          <v-alert v-if="error" type="error" variant="tonal" class="mb-5" density="comfortable">{{ error }}</v-alert>
          <v-form @submit.prevent="submit">
            <v-text-field
              v-model="email"
              label="Email address"
              type="email"
              autocomplete="username"
              prepend-inner-icon="mdi-email-outline"
              variant="outlined"
              class="mb-2"
              required
            />
            <v-text-field
              v-model="password"
              label="Password"
              type="password"
              autocomplete="current-password"
              prepend-inner-icon="mdi-lock-outline"
              variant="outlined"
              required
            />
            <div class="d-flex align-center justify-space-between mb-5">
              <v-checkbox v-model="remember" label="Remember me" density="compact" hide-details color="primary" />
              <RouterLink class="text-decoration-none text-primary text-body-2 font-weight-medium" to="/forgot-password">Forgot password?</RouterLink>
            </div>
            <v-btn type="submit" color="primary" size="large" block rounded="lg" :loading="loading">Sign in</v-btn>
          </v-form>
          <p class="text-caption text-medium-emphasis mt-6 text-center">
            Patient portal accounts are issued by the clinic administrator.
          </p>
          <p class="text-caption text-center mt-2">
            <RouterLink class="text-decoration-none text-primary font-weight-medium" to="/login">Staff workspace sign in</RouterLink>
          </p>
        </v-card>
        <p class="auth-foot text-center mt-7">Private and secure access to your clinic records</p>
      </v-col>
    </v-row>
  </v-container>
</template>

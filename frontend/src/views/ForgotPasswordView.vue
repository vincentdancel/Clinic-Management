<script setup lang="ts">
import { ref } from 'vue'
import { api } from '@/services/api'
import { prepareCsrfCookie } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

const email = ref('')
const loading = ref(false)
const sent = ref(false)
const notifications = useNotificationsStore()

async function submit() {
  loading.value = true
  try {
    await prepareCsrfCookie()
    const { data } = await api.post('/auth/forgot-password', { email: email.value })
    sent.value = true
    notifications.show(data.message, 'success')
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? 'Unable to send a reset link.', 'error')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-container class="auth-page fill-height" fluid><v-row align="center" justify="center"><v-col cols="12" sm="9" md="6" lg="4">
    <div class="auth-brand mb-8"><span class="brand-mark">C</span><span>CLINIC <b>CARE</b></span></div>
    <v-card class="auth-card pa-7 pa-md-9" rounded="xl" elevation="0">
      <p class="eyebrow">ACCOUNT RECOVERY</p><h1 class="text-h4 font-weight-bold mb-2">Reset your password</h1>
      <p class="text-medium-emphasis mb-7">Enter your work email and we’ll send a reset link if the account exists.</p>
      <v-alert v-if="sent" type="success" variant="tonal" class="mb-5">Check your inbox for next steps.</v-alert>
      <v-form @submit.prevent="submit"><v-text-field v-model="email" label="Email address" type="email" autocomplete="email" prepend-inner-icon="mdi-email-outline" variant="outlined" required />
        <v-btn type="submit" color="primary" size="large" block rounded="lg" :loading="loading">Send reset link</v-btn>
      </v-form>
      <div class="text-center mt-6"><RouterLink to="/login" class="text-decoration-none text-primary font-weight-medium">Back to sign in</RouterLink></div>
    </v-card>
  </v-col></v-row></v-container>
</template>

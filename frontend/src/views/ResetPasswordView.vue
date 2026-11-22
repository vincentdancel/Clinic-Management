<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, prepareCsrfCookie } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

const route = useRoute()
const router = useRouter()
const notifications = useNotificationsStore()
const password = ref('')
const passwordConfirmation = ref('')
const loading = ref(false)
const error = ref('')

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await prepareCsrfCookie()
    const { data } = await api.post('/auth/reset-password', {
      token: route.query.token,
      email: route.query.email,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    notifications.show(data.message, 'success')
    await router.replace('/login')
  } catch (cause: any) {
    error.value = cause.response?.data?.message ?? 'Unable to reset your password.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <v-container class="auth-page fill-height" fluid><v-row align="center" justify="center"><v-col cols="12" sm="9" md="6" lg="4">
    <div class="auth-brand mb-8"><span class="brand-mark">C</span><span>CLINIC <b>CARE</b></span></div>
    <v-card class="auth-card pa-7 pa-md-9" rounded="xl" elevation="0">
      <p class="eyebrow">ACCOUNT RECOVERY</p><h1 class="text-h4 font-weight-bold mb-2">Choose a new password</h1>
      <v-alert v-if="error" type="error" variant="tonal" class="my-5">{{ error }}</v-alert>
      <v-form @submit.prevent="submit"><v-text-field v-model="password" label="New password" type="password" autocomplete="new-password" variant="outlined" minlength="8" required />
        <v-text-field v-model="passwordConfirmation" label="Confirm new password" type="password" autocomplete="new-password" variant="outlined" required />
        <v-btn type="submit" color="primary" size="large" block rounded="lg" :loading="loading">Update password</v-btn>
      </v-form>
    </v-card>
  </v-col></v-row></v-container>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

const notifications = useNotificationsStore()
const saving = ref(false)
const form = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})

async function updatePassword() {
  saving.value = true
  try {
    const { data } = await api.put<{ message: string }>('/patient/password', form)
    Object.assign(form, { current_password: '', password: '', password_confirmation: '' })
    notifications.show(data.message, 'success')
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not update password.'), 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section>
    <v-card rounded="xl" elevation="0" class="pa-6" max-width="560">
      <h2 class="text-h6 font-weight-bold mb-4">Change Password</h2>
      <v-form @submit.prevent="updatePassword">
        <v-text-field v-model="form.current_password" label="Current password" type="password" variant="outlined" class="mb-3" required />
        <v-text-field v-model="form.password" label="New password" type="password" variant="outlined" class="mb-3" required />
        <v-text-field v-model="form.password_confirmation" label="Confirm new password" type="password" variant="outlined" class="mb-4" required />
        <v-btn type="submit" color="primary" :loading="saving">Update password</v-btn>
      </v-form>
    </v-card>
  </section>
</template>

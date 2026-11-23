<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import { usePatientAuthStore, type PatientPortalProfile } from '@/stores/patientAuth'

const notifications = useNotificationsStore()
const patientAuth = usePatientAuthStore()
const loading = ref(true)
const saving = ref(false)
const profile = ref<PatientPortalProfile | null>(null)

const form = reactive({
  phone: '',
  email: '',
  address: '',
  emergency_contact_name: '',
  emergency_contact_phone: '',
})

async function loadProfile() {
  loading.value = true
  try {
    const { data } = await api.get<{ data: PatientPortalProfile }>('/patient/profile')
    profile.value = data.data
    form.phone = data.data.phone ?? ''
    form.email = data.data.email ?? ''
    form.address = data.data.address ?? ''
    form.emergency_contact_name = data.data.emergency_contact_name ?? ''
    form.emergency_contact_phone = data.data.emergency_contact_phone ?? ''
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load your profile.'), 'error')
  } finally {
    loading.value = false
  }
}

async function saveProfile() {
  saving.value = true
  try {
    const { data } = await api.put<{ message: string; data: PatientPortalProfile }>('/patient/profile', {
      phone: form.phone || null,
      email: form.email || null,
      address: form.address || null,
      emergency_contact_name: form.emergency_contact_name || null,
      emergency_contact_phone: form.emergency_contact_phone || null,
    })
    profile.value = data.data
    await patientAuth.refresh()
    notifications.show(data.message, 'success')
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not update your profile.'), 'error')
  } finally {
    saving.value = false
  }
}

onMounted(loadProfile)
</script>

<template>
  <section>
    <div v-if="loading" class="d-flex justify-center py-12">
      <v-progress-circular indeterminate color="primary" />
    </div>
    <v-row v-else-if="profile">
      <v-col cols="12" md="5">
        <v-card rounded="xl" elevation="0" class="pa-6">
          <p class="eyebrow mb-1">{{ profile.patient_number }}</p>
          <h2 class="text-h5 font-weight-bold mb-4">{{ profile.full_name }}</h2>
          <v-list density="compact" class="pa-0">
            <v-list-item title="Date of Birth" :subtitle="profile.date_of_birth || '—'" />
            <v-list-item title="Gender" :subtitle="profile.gender || '—'" />
            <v-list-item title="Status" :subtitle="profile.status" />
          </v-list>
        </v-card>
      </v-col>
      <v-col cols="12" md="7">
        <v-card rounded="xl" elevation="0" class="pa-6">
          <h2 class="text-h6 font-weight-bold mb-4">Contact & Emergency Information</h2>
          <v-form @submit.prevent="saveProfile">
            <v-text-field v-model="form.phone" label="Phone number" variant="outlined" class="mb-3" />
            <v-text-field v-model="form.email" label="Contact email" type="email" variant="outlined" class="mb-3" />
            <v-textarea v-model="form.address" label="Address" variant="outlined" rows="2" class="mb-3" />
            <v-text-field v-model="form.emergency_contact_name" label="Emergency contact name" variant="outlined" class="mb-3" />
            <v-text-field v-model="form.emergency_contact_phone" label="Emergency contact phone" variant="outlined" class="mb-4" />
            <v-btn type="submit" color="primary" :loading="saving">Save contact details</v-btn>
          </v-form>
        </v-card>
      </v-col>
    </v-row>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

interface PortalConsultation {
  id: number
  consultation_number: string
  started_at: string
  chief_complaint: string | null
  diagnosis: string | null
  treatment_plan: string | null
  follow_up_date: string | null
  doctor: { full_name: string } | null
}

const notifications = useNotificationsStore()
const loading = ref(true)
const consultations = ref<PortalConsultation[]>([])

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await api.get<{ data: PortalConsultation[] }>('/patient/consultations')
    consultations.value = data.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load consultations.'), 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <div v-if="loading" class="d-flex justify-center py-12"><v-progress-circular indeterminate color="primary" /></div>
    <v-card v-else rounded="xl" elevation="0" class="pa-5">
      <h2 class="text-h6 font-weight-bold mb-3">Released Consultations</h2>
      <v-list v-if="consultations.length" lines="two">
        <v-list-item v-for="item in consultations" :key="item.id">
          <v-list-item-title>{{ item.consultation_number }} · {{ item.doctor?.full_name || 'Clinician' }}</v-list-item-title>
          <v-list-item-subtitle>
            Diagnosis: {{ item.diagnosis || '—' }} · Treatment: {{ item.treatment_plan || '—' }}
          </v-list-item-subtitle>
        </v-list-item>
      </v-list>
      <p v-else class="text-body-2 text-medium-emphasis">No released consultations available.</p>
    </v-card>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

interface PortalPrescription {
  id: number
  prescribed_at: string
  status: string
  notes: string | null
  prescriber: { full_name: string } | null
  items: Array<{ id: number; medication_name: string; dosage: string; frequency: string; duration_days: number | null }>
}

const notifications = useNotificationsStore()
const loading = ref(true)
const prescriptions = ref<PortalPrescription[]>([])

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await api.get<{ data: PortalPrescription[] }>('/patient/prescriptions')
    prescriptions.value = data.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load prescriptions.'), 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <div v-if="loading" class="d-flex justify-center py-12"><v-progress-circular indeterminate color="primary" /></div>
    <v-card v-else rounded="xl" elevation="0" class="pa-5">
      <h2 class="text-h6 font-weight-bold mb-3">My Prescriptions</h2>
      <v-list v-if="prescriptions.length" lines="two">
        <v-list-item v-for="rx in prescriptions" :key="rx.id">
          <v-list-item-title>{{ rx.prescribed_at }} · {{ rx.prescriber?.full_name || 'Doctor' }} ({{ rx.status }})</v-list-item-title>
          <v-list-item-subtitle>
            {{ rx.items.map((i) => `${i.medication_name} (${i.dosage}, ${i.frequency})`).join('; ') || rx.notes || 'No items' }}
          </v-list-item-subtitle>
        </v-list-item>
      </v-list>
      <p v-else class="text-body-2 text-medium-emphasis">No prescriptions available.</p>
    </v-card>
  </section>
</template>

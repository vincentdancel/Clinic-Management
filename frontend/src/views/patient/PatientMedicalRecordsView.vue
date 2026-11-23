<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

interface MedicalRecordsPayload {
  allergies: Array<{ id: number; allergen: string; reaction: string | null; severity: string | null }>
  medical_histories: Array<{ id: number; condition_name: string; details: string | null; diagnosed_at: string | null }>
  released_consultations: Array<{ id: number; consultation_number: string; diagnosis: string | null; treatment_plan: string | null; started_at: string }>
}

const notifications = useNotificationsStore()
const loading = ref(true)
const records = ref<MedicalRecordsPayload | null>(null)

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await api.get<MedicalRecordsPayload>('/patient/medical-records')
    records.value = data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load medical records.'), 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <div v-if="loading" class="d-flex justify-center py-12"><v-progress-circular indeterminate color="primary" /></div>
    <v-row v-else-if="records">
      <v-col cols="12" md="6">
        <v-card rounded="xl" elevation="0" class="pa-5">
          <h2 class="text-h6 font-weight-bold mb-3">Allergies</h2>
          <v-list v-if="records.allergies.length" density="compact">
            <v-list-item v-for="item in records.allergies" :key="item.id" :title="item.allergen" :subtitle="[item.severity, item.reaction].filter(Boolean).join(' · ') || 'No notes'" />
          </v-list>
          <p v-else class="text-body-2 text-medium-emphasis">No allergies recorded.</p>
        </v-card>
      </v-col>
      <v-col cols="12" md="6">
        <v-card rounded="xl" elevation="0" class="pa-5">
          <h2 class="text-h6 font-weight-bold mb-3">Medical History</h2>
          <v-list v-if="records.medical_histories.length" density="compact">
            <v-list-item v-for="item in records.medical_histories" :key="item.id" :title="item.condition_name" :subtitle="item.details || item.diagnosed_at || 'Recorded condition'" />
          </v-list>
          <p v-else class="text-body-2 text-medium-emphasis">No medical history recorded.</p>
        </v-card>
      </v-col>
    </v-row>
  </section>
</template>

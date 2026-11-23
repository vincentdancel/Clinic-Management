<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

interface PortalAppointment {
  id: number
  appointment_number: string
  scheduled_at: string
  status: string
  reason: string | null
  doctor: { full_name: string; job_title?: string; department?: { name: string } | null } | null
}

const notifications = useNotificationsStore()
const loading = ref(true)
const upcoming = ref<PortalAppointment[]>([])
const history = ref<PortalAppointment[]>([])

function formatDateTime(value: string): string {
  return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date(value))
}

onMounted(async () => {
  loading.value = true
  try {
    const { data } = await api.get<{ upcoming: PortalAppointment[]; history: PortalAppointment[] }>('/patient/appointments')
    upcoming.value = data.upcoming
    history.value = data.history
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load appointments.'), 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <div v-if="loading" class="d-flex justify-center py-12"><v-progress-circular indeterminate color="primary" /></div>
    <v-row v-else>
      <v-col cols="12" md="6">
        <v-card rounded="xl" elevation="0" class="pa-5">
          <h2 class="text-h6 font-weight-bold mb-3">Upcoming Appointments</h2>
          <v-list v-if="upcoming.length" density="compact">
            <v-list-item v-for="item in upcoming" :key="item.id">
              <v-list-item-title>{{ item.doctor?.full_name || 'Physician' }} · {{ item.appointment_number }}</v-list-item-title>
              <v-list-item-subtitle>{{ formatDateTime(item.scheduled_at) }} · {{ item.status }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <p v-else class="text-body-2 text-medium-emphasis">No upcoming appointments.</p>
        </v-card>
      </v-col>
      <v-col cols="12" md="6">
        <v-card rounded="xl" elevation="0" class="pa-5">
          <h2 class="text-h6 font-weight-bold mb-3">Appointment History</h2>
          <v-list v-if="history.length" density="compact">
            <v-list-item v-for="item in history" :key="item.id">
              <v-list-item-title>{{ item.doctor?.full_name || 'Physician' }} · {{ item.appointment_number }}</v-list-item-title>
              <v-list-item-subtitle>{{ formatDateTime(item.scheduled_at) }} · {{ item.status }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <p v-else class="text-body-2 text-medium-emphasis">No appointment history.</p>
        </v-card>
      </v-col>
    </v-row>
  </section>
</template>

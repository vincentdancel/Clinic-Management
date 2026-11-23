<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import type { PatientPortalProfile } from '@/stores/patientAuth'

interface PortalDashboardPayload {
  patient: PatientPortalProfile
  upcoming_appointments: Array<{
    id: number
    appointment_number: string
    scheduled_at: string
    status: string
    reason: string | null
    doctor: { full_name: string; job_title: string } | null
  }>
  previous_appointments: Array<{
    id: number
    appointment_number: string
    scheduled_at: string
    status: string
    reason: string | null
    doctor: { full_name: string } | null
  }>
  recent_consultations: Array<{
    id: number
    consultation_number: string
    started_at: string
    chief_complaint: string | null
    diagnosis: string | null
    doctor: { full_name: string } | null
  }>
  medical_records: {
    allergies: Array<{ id: number; allergen: string; severity: string | null }>
    medical_histories: Array<{ id: number; condition_name: string; diagnosed_at: string | null }>
  }
  active_prescriptions: Array<{
    id: number
    prescribed_at: string
    status: string
    prescriber: { full_name: string } | null
    items: Array<{ id: number; medication_name: string; dosage: string; frequency: string }>
  }>
  outstanding_invoices: Array<{
    id: number
    invoice_number: string
    issued_at: string
    remaining_balance: number
    status: string
  }>
  recent_payments: Array<{
    id: number
    receipt_number: string
    amount: number
    paid_at: string
    method: string
  }>
}

const notifications = useNotificationsStore()
const loading = ref(true)
const dashboard = ref<PortalDashboardPayload | null>(null)

function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

function formatMoney(value: number): string {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

async function loadDashboard() {
  loading.value = true
  try {
    const { data } = await api.get<PortalDashboardPayload>('/patient/dashboard')
    dashboard.value = data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load your dashboard.'), 'error')
  } finally {
    loading.value = false
  }
}

onMounted(loadDashboard)
</script>

<template>
  <section>
    <div v-if="loading" class="d-flex align-center justify-center py-12">
      <v-progress-circular indeterminate color="primary" />
    </div>

    <template v-else-if="dashboard">
      <v-card rounded="xl" elevation="0" class="pa-6 mb-6">
        <div class="d-flex flex-wrap align-center justify-space-between ga-4">
          <div>
            <p class="eyebrow mb-1">{{ dashboard.patient.patient_number }}</p>
            <h1 class="text-h4 font-weight-bold">Welcome, {{ dashboard.patient.full_name }}</h1>
            <p class="text-medium-emphasis mb-0">View your upcoming visits, released medical records, prescriptions, and billing.</p>
          </div>
          <v-btn to="/patient/profile" color="primary" variant="tonal" prepend-icon="mdi-account-outline">
            View My Profile
          </v-btn>
        </div>
      </v-card>

      <v-row>
        <v-col cols="12" md="6">
          <v-card rounded="xl" elevation="0" class="pa-5 h-100">
            <div class="d-flex align-center justify-space-between mb-3">
              <h2 class="text-h6 font-weight-bold">Upcoming Appointments</h2>
              <v-btn to="/patient/appointments" variant="text" size="small">View all</v-btn>
            </div>
            <v-list v-if="dashboard.upcoming_appointments.length" density="compact">
              <v-list-item v-for="item in dashboard.upcoming_appointments" :key="item.id">
                <v-list-item-title>{{ item.doctor?.full_name || 'Clinic Physician' }} · {{ item.appointment_number }}</v-list-item-title>
                <v-list-item-subtitle>{{ formatDateTime(item.scheduled_at) }} · {{ item.status }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
            <p v-else class="text-body-2 text-medium-emphasis">No upcoming appointments scheduled.</p>
          </v-card>
        </v-col>

        <v-col cols="12" md="6">
          <v-card rounded="xl" elevation="0" class="pa-5 h-100">
            <div class="d-flex align-center justify-space-between mb-3">
              <h2 class="text-h6 font-weight-bold">Previous Appointments</h2>
              <v-btn to="/patient/appointments" variant="text" size="small">History</v-btn>
            </div>
            <v-list v-if="dashboard.previous_appointments.length" density="compact">
              <v-list-item v-for="item in dashboard.previous_appointments" :key="item.id">
                <v-list-item-title>{{ item.doctor?.full_name || 'Clinic Physician' }} · {{ item.appointment_number }}</v-list-item-title>
                <v-list-item-subtitle>{{ formatDateTime(item.scheduled_at) }} · {{ item.status }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
            <p v-else class="text-body-2 text-medium-emphasis">No previous appointments on record.</p>
          </v-card>
        </v-col>

        <v-col cols="12" md="6">
          <v-card rounded="xl" elevation="0" class="pa-5 h-100">
            <div class="d-flex align-center justify-space-between mb-3">
              <h2 class="text-h6 font-weight-bold">Recent Consultations</h2>
              <v-btn to="/patient/consultations" variant="text" size="small">View all</v-btn>
            </div>
            <v-list v-if="dashboard.recent_consultations.length" density="compact">
              <v-list-item v-for="item in dashboard.recent_consultations" :key="item.id">
                <v-list-item-title>{{ item.consultation_number }} · {{ item.doctor?.full_name || 'Clinician' }}</v-list-item-title>
                <v-list-item-subtitle>{{ item.diagnosis || item.chief_complaint || 'Released consultation' }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
            <p v-else class="text-body-2 text-medium-emphasis">No released consultations available yet.</p>
          </v-card>
        </v-col>

        <v-col cols="12" md="6">
          <v-card rounded="xl" elevation="0" class="pa-5 h-100">
            <div class="d-flex align-center justify-space-between mb-3">
              <h2 class="text-h6 font-weight-bold">Available Medical Records</h2>
              <v-btn to="/patient/medical-records" variant="text" size="small">View all</v-btn>
            </div>
            <p class="text-body-2 mb-1">
              <strong>Recorded Allergies:</strong> {{ dashboard.medical_records.allergies.length }}
            </p>
            <p class="text-body-2 mb-0">
              <strong>Medical History Conditions:</strong> {{ dashboard.medical_records.medical_histories.length }}
            </p>
          </v-card>
        </v-col>

        <v-col cols="12" md="4">
          <v-card rounded="xl" elevation="0" class="pa-5 h-100">
            <div class="d-flex align-center justify-space-between mb-3">
              <h2 class="text-h6 font-weight-bold">Active Prescriptions</h2>
              <v-btn to="/patient/prescriptions" variant="text" size="small">View all</v-btn>
            </div>
            <v-list v-if="dashboard.active_prescriptions.length" density="compact">
              <v-list-item v-for="rx in dashboard.active_prescriptions" :key="rx.id">
                <v-list-item-title>{{ rx.prescribed_at }}</v-list-item-title>
                <v-list-item-subtitle>{{ rx.items.length }} item(s) · {{ rx.status }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
            <p v-else class="text-body-2 text-medium-emphasis">No active prescriptions.</p>
          </v-card>
        </v-col>

        <v-col cols="12" md="4">
          <v-card rounded="xl" elevation="0" class="pa-5 h-100">
            <div class="d-flex align-center justify-space-between mb-3">
              <h2 class="text-h6 font-weight-bold">Outstanding Invoices</h2>
              <v-btn to="/patient/billing" variant="text" size="small">View all</v-btn>
            </div>
            <v-list v-if="dashboard.outstanding_invoices.length" density="compact">
              <v-list-item v-for="inv in dashboard.outstanding_invoices" :key="inv.id">
                <v-list-item-title>{{ inv.invoice_number }}</v-list-item-title>
                <v-list-item-subtitle>Balance: {{ formatMoney(inv.remaining_balance) }} · {{ inv.status }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
            <p v-else class="text-body-2 text-medium-emphasis">No outstanding invoices.</p>
          </v-card>
        </v-col>

        <v-col cols="12" md="4">
          <v-card rounded="xl" elevation="0" class="pa-5 h-100">
            <div class="d-flex align-center justify-space-between mb-3">
              <h2 class="text-h6 font-weight-bold">Recent Payments</h2>
              <v-btn to="/patient/billing" variant="text" size="small">View all</v-btn>
            </div>
            <v-list v-if="dashboard.recent_payments.length" density="compact">
              <v-list-item v-for="pmt in dashboard.recent_payments" :key="pmt.id">
                <v-list-item-title>{{ pmt.receipt_number }} · {{ formatMoney(pmt.amount) }}</v-list-item-title>
                <v-list-item-subtitle>{{ formatDateTime(pmt.paid_at) }} · {{ pmt.method }}</v-list-item-subtitle>
              </v-list-item>
            </v-list>
            <p v-else class="text-body-2 text-medium-emphasis">No recent payments.</p>
          </v-card>
        </v-col>
      </v-row>
    </template>
  </section>
</template>

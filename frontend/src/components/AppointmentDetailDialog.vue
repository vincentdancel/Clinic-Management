<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import {
  APPOINTMENT_STATUS_META,
  formatAppointmentDate,
  formatAppointmentTime,
  type AppointmentHistoryRecord,
  type AppointmentRecord,
  type AppointmentStatus,
  type DoctorOption,
  type TimeSlot,
} from '@/types/appointment'

const props = defineProps<{
  modelValue: boolean
  appointment: AppointmentRecord | null
  doctors: DoctorOption[]
  canManage: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  updated: []
}>()

const notifications = useNotificationsStore()
const detail = ref<AppointmentRecord | null>(null)
const histories = ref<AppointmentHistoryRecord[]>([])
const patientAppointments = ref<AppointmentRecord[]>([])
const activePanel = ref<'overview' | 'reschedule' | 'cancel'>('overview')
const busy = reactive({ loading: false, saving: false, loadingSlots: false })
const slots = ref<TimeSlot[]>([])

const rescheduleForm = reactive({
  doctor_id: null as number | null,
  appointment_date: '',
  appointment_time: '',
  duration_minutes: 30,
  notes: '',
})

const cancelReason = ref('')
const todayString = new Date().toISOString().slice(0, 10)

const current = computed(() => detail.value ?? props.appointment)
const statusMeta = computed(() => APPOINTMENT_STATUS_META[current.value?.status ?? 'pending'])
const isTerminal = computed(() => current.value?.status === 'completed' || current.value?.status === 'cancelled')

watch(
  () => [props.modelValue, props.appointment?.id],
  ([isOpen, id]) => {
    if (isOpen && id) {
      activePanel.value = 'overview'
      cancelReason.value = ''
      void loadDetails(Number(id))
    }
  },
)

watch(
  () => [activePanel.value, rescheduleForm.doctor_id, rescheduleForm.appointment_date, rescheduleForm.duration_minutes],
  () => {
    if (activePanel.value === 'reschedule' && rescheduleForm.doctor_id && rescheduleForm.appointment_date) {
      void loadRescheduleSlots()
    }
  },
)

async function loadDetails(id: number) {
  busy.loading = true
  try {
    const [showRes, histRes] = await Promise.all([
      api.get<{ data: AppointmentRecord }>(`/appointments/${id}`),
      api.get<{ histories: AppointmentHistoryRecord[]; patient_appointments: AppointmentRecord[] }>(`/appointments/${id}/history`),
    ])
    detail.value = showRes.data.data
    histories.value = histRes.data.histories
    patientAppointments.value = histRes.data.patient_appointments
  } catch {
    detail.value = props.appointment
  } finally {
    busy.loading = false
  }
}

function openReschedule() {
  if (!current.value) return
  rescheduleForm.doctor_id = current.value.doctor_id
  rescheduleForm.appointment_date = current.value.appointment_date
  rescheduleForm.appointment_time = current.value.appointment_time
  rescheduleForm.duration_minutes = current.value.duration_minutes
  rescheduleForm.notes = ''
  activePanel.value = 'reschedule'
}

async function loadRescheduleSlots() {
  if (!rescheduleForm.doctor_id || !rescheduleForm.appointment_date) return
  busy.loadingSlots = true
  try {
    const response = await api.get<{ slots: TimeSlot[] }>('/appointments/available-slots', {
      params: {
        doctor_id: rescheduleForm.doctor_id,
        date: rescheduleForm.appointment_date,
        duration_minutes: rescheduleForm.duration_minutes,
        exclude_appointment_id: current.value?.id,
      },
    })
    slots.value = response.data.slots
  } catch {
    slots.value = []
  } finally {
    busy.loadingSlots = false
  }
}

async function changeStatus(status: AppointmentStatus) {
  if (!current.value) return
  busy.saving = true
  try {
    await api.patch(`/appointments/${current.value.id}/status`, { status })
    notifications.show(`Appointment marked as ${APPOINTMENT_STATUS_META[status].label}.`, 'success')
    await loadDetails(current.value.id)
    emit('updated')
  } catch (error: any) {
    const firstError = Object.values(error.response?.data?.errors ?? {}).flat()[0]
    notifications.show(typeof firstError === 'string' ? firstError : error.response?.data?.message ?? 'Could not update status.', 'error')
  } finally {
    busy.saving = false
  }
}

async function submitReschedule() {
  if (!current.value) return
  busy.saving = true
  try {
    await api.patch(`/appointments/${current.value.id}/reschedule`, {
      doctor_id: rescheduleForm.doctor_id,
      appointment_date: rescheduleForm.appointment_date,
      appointment_time: rescheduleForm.appointment_time,
      duration_minutes: rescheduleForm.duration_minutes,
      notes: rescheduleForm.notes || undefined,
    })
    notifications.show('Appointment rescheduled.', 'success')
    activePanel.value = 'overview'
    await loadDetails(current.value.id)
    emit('updated')
  } catch (error: any) {
    const firstError = Object.values(error.response?.data?.errors ?? {}).flat()[0]
    notifications.show(typeof firstError === 'string' ? firstError : error.response?.data?.message ?? 'Could not reschedule appointment.', 'error')
  } finally {
    busy.saving = false
  }
}

async function submitCancel() {
  if (!current.value) return
  busy.saving = true
  try {
    await api.post(`/appointments/${current.value.id}/cancel`, {
      cancellation_reason: cancelReason.value || null,
    })
    notifications.show('Appointment cancelled.', 'success')
    activePanel.value = 'overview'
    await loadDetails(current.value.id)
    emit('updated')
  } catch (error: any) {
    const firstError = Object.values(error.response?.data?.errors ?? {}).flat()[0]
    notifications.show(typeof firstError === 'string' ? firstError : error.response?.data?.message ?? 'Could not cancel appointment.', 'error')
  } finally {
    busy.saving = false
  }
}

function formatDateTime(value: string | null): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="820" scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card v-if="current" rounded="xl" class="patient-form-card">
      <div class="dialog-heading">
        <div>
          <div class="d-flex align-center ga-2">
            <p class="eyebrow">{{ current.appointment_number ?? `APT-${current.id}` }}</p>
            <v-chip size="x-small" :color="statusMeta.color" variant="tonal" :prepend-icon="statusMeta.icon">
              {{ statusMeta.label }}
            </v-chip>
          </div>
          <h2>{{ current.patient?.full_name ?? 'Patient Appointment' }}</h2>
          <p>
            {{ formatAppointmentDate(current.scheduled_at) }} · {{ formatAppointmentTime(current.scheduled_at) }}
            ({{ current.duration_minutes }} mins) · Dr. {{ current.doctor?.full_name ?? 'Unassigned' }}
          </p>
        </div>
        <v-btn icon="mdi-close" variant="text" aria-label="Close" @click="emit('update:modelValue', false)" />
      </div>
      <v-divider />

      <v-card-text class="patient-form-content">
        <div v-if="canManage && activePanel === 'overview'" class="action-bar">
          <v-btn
            v-if="current.status === 'pending'"
            size="small"
            color="info"
            variant="tonal"
            prepend-icon="mdi-check-circle-outline"
            rounded="lg"
            :loading="busy.saving"
            @click="changeStatus('confirmed')"
          >
            Confirm
          </v-btn>
          <v-btn
            v-if="current.status === 'pending' || current.status === 'confirmed'"
            size="small"
            color="success"
            variant="tonal"
            prepend-icon="mdi-check-all"
            rounded="lg"
            :loading="busy.saving"
            @click="changeStatus('completed')"
          >
            Mark completed
          </v-btn>
          <v-btn
            v-if="current.status === 'pending' || current.status === 'confirmed'"
            size="small"
            color="secondary"
            variant="tonal"
            prepend-icon="mdi-account-off-outline"
            rounded="lg"
            :loading="busy.saving"
            @click="changeStatus('no_show')"
          >
            No show
          </v-btn>
          <v-btn
            v-if="!isTerminal"
            size="small"
            color="primary"
            variant="outlined"
            prepend-icon="mdi-calendar-edit"
            rounded="lg"
            @click="openReschedule"
          >
            Reschedule
          </v-btn>
          <v-btn
            v-if="!isTerminal"
            size="small"
            color="error"
            variant="outlined"
            prepend-icon="mdi-close-circle-outline"
            rounded="lg"
            @click="activePanel = 'cancel'"
          >
            Cancel appointment
          </v-btn>
        </div>

        <!-- Reschedule panel -->
        <div v-if="activePanel === 'reschedule'" class="sub-panel">
          <h3>Reschedule appointment</h3>
          <v-row dense>
            <v-col cols="12" sm="5">
              <v-select
                v-model="rescheduleForm.doctor_id"
                :items="doctors"
                item-title="full_name"
                item-value="id"
                label="Doctor"
                variant="outlined"
                density="comfortable"
              />
            </v-col>
            <v-col cols="7" sm="4">
              <v-text-field
                v-model="rescheduleForm.appointment_date"
                type="date"
                :min="todayString"
                label="New date"
                variant="outlined"
                density="comfortable"
              />
            </v-col>
            <v-col cols="5" sm="3">
              <v-text-field
                v-model="rescheduleForm.appointment_time"
                type="time"
                label="New time"
                variant="outlined"
                density="comfortable"
              />
            </v-col>
          </v-row>
          <div v-if="slots.length" class="slot-grid mb-3">
            <v-btn
              v-for="slot in slots"
              :key="slot.time"
              size="x-small"
              rounded="lg"
              :disabled="!slot.available"
              :color="rescheduleForm.appointment_time === slot.time ? 'primary' : undefined"
              :variant="rescheduleForm.appointment_time === slot.time ? 'flat' : 'outlined'"
              @click="rescheduleForm.appointment_time = slot.time"
            >
              {{ slot.label }}
            </v-btn>
          </div>
          <v-text-field
            v-model="rescheduleForm.notes"
            label="Reschedule notes (optional)"
            variant="outlined"
            density="comfortable"
          />
          <div class="dialog-actions">
            <v-btn variant="text" rounded="lg" @click="activePanel = 'overview'">Back</v-btn>
            <v-btn color="primary" rounded="lg" :loading="busy.saving" @click="submitReschedule">Save new schedule</v-btn>
          </div>
        </div>

        <!-- Cancel panel -->
        <div v-else-if="activePanel === 'cancel'" class="sub-panel">
          <h3>Cancel appointment</h3>
          <v-textarea
            v-model="cancelReason"
            label="Cancellation reason (optional)"
            placeholder="e.g., Patient requested cancellation, doctor unavailable"
            variant="outlined"
            density="comfortable"
            rows="2"
          />
          <div class="dialog-actions">
            <v-btn variant="text" rounded="lg" @click="activePanel = 'overview'">Back</v-btn>
            <v-btn color="error" rounded="lg" :loading="busy.saving" @click="submitCancel">Confirm cancellation</v-btn>
          </div>
        </div>

        <!-- Overview & History -->
        <template v-else>
          <div class="detail-fields mb-5">
            <div>
              <span>Patient</span>
              <b>
                <RouterLink v-if="current.patient" :to="`/patients/${current.patient.id}`" class="text-primary text-decoration-none">
                  {{ current.patient.full_name }} ({{ current.patient.patient_number }})
                </RouterLink>
                <template v-else>—</template>
              </b>
            </div>
            <div>
              <span>Assigned doctor</span>
              <b>
                Dr. {{ current.doctor?.full_name ?? '—' }}
                <small v-if="current.doctor?.specializations?.length">
                  · {{ current.doctor.specializations.map((s) => s.name).join(', ') }}
                </small>
              </b>
            </div>
            <div class="detail-field-wide">
              <span>Reason for visit</span>
              <b>{{ current.reason || 'No reason specified' }}</b>
            </div>
            <div v-if="current.cancellation_reason" class="detail-field-wide">
              <span>Cancellation reason</span>
              <b class="text-error">{{ current.cancellation_reason }}</b>
            </div>
          </div>

          <v-divider class="mb-4" />
          <h3>Appointment history</h3>
          <div v-if="busy.loading" class="clinical-empty">Loading history…</div>
          <div v-else-if="!histories.length" class="clinical-empty">No history entries recorded yet.</div>
          <div v-else class="history-timeline">
            <div v-for="entry in histories" :key="entry.id" class="history-item">
              <div class="history-dot"></div>
              <div class="history-copy">
                <div class="history-head">
                  <b>{{ entry.action.replaceAll('_', ' ') }}</b>
                  <span>{{ formatDateTime(entry.created_at) }}</span>
                </div>
                <p v-if="entry.from_status && entry.to_status && entry.from_status !== entry.to_status">
                  Status: {{ entry.from_status.replaceAll('_', ' ') }} → {{ entry.to_status.replaceAll('_', ' ') }}
                </p>
                <p v-if="entry.from_scheduled_at && entry.to_scheduled_at">
                  Rescheduled: {{ formatDateTime(entry.from_scheduled_at) }} → {{ formatDateTime(entry.to_scheduled_at) }}
                </p>
                <p v-if="entry.notes">{{ entry.notes }}</p>
                <small v-if="entry.actor">By {{ entry.actor.name }}</small>
              </div>
            </div>
          </div>

          <template v-if="patientAppointments.length > 1">
            <v-divider class="my-4" />
            <h3>Patient’s recent appointments</h3>
            <div class="past-list">
              <div v-for="item in patientAppointments" :key="item.id" class="past-row">
                <span>{{ formatAppointmentDate(item.scheduled_at) }} · {{ formatAppointmentTime(item.scheduled_at) }}</span>
                <b>Dr. {{ item.doctor?.full_name ?? '—' }}</b>
                <v-chip size="x-small" :color="APPOINTMENT_STATUS_META[item.status].color" variant="tonal">
                  {{ APPOINTMENT_STATUS_META[item.status].label }}
                </v-chip>
              </div>
            </div>
          </template>
        </template>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.action-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 18px;
}
.sub-panel {
  padding: 16px;
  border: 1px solid #e9eef4;
  border-radius: 12px;
  background: #fafcfe;
}
.slot-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.history-timeline {
  display: grid;
  gap: 12px;
}
.history-item {
  display: flex;
  gap: 10px;
  padding-left: 4px;
}
.history-dot {
  width: 8px;
  height: 8px;
  margin-top: 5px;
  border-radius: 50%;
  background: #197fc0;
  flex: 0 0 8px;
}
.history-copy {
  flex: 1;
  min-width: 0;
}
.history-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.history-head b {
  color: #2c4054;
  font-size: 11px;
  text-transform: capitalize;
}
.history-head span {
  color: #8d9aa8;
  font-size: 10px;
}
.history-copy p {
  margin: 3px 0 0;
  color: #56677a;
  font-size: 11px;
}
.history-copy small {
  color: #9aa7b4;
  font-size: 10px;
}
.past-list {
  display: grid;
  gap: 8px;
}
.past-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 10px;
  border: 1px solid #eef2f6;
  border-radius: 9px;
  font-size: 11px;
}
.past-row span {
  color: #65778a;
}
.past-row b {
  color: #2e4257;
  flex: 1;
}
</style>


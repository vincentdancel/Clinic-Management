<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import type { DoctorOption, PatientOption, TimeSlot } from '@/types/appointment'

const props = defineProps<{
  modelValue: boolean
  doctors: DoctorOption[]
  initialPatients: PatientOption[]
  initialDate?: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: []
}>()

const notifications = useNotificationsStore()
const busy = reactive({ saving: false, loadingSlots: false, searchingPatients: false })
const patientOptions = ref<PatientOption[]>([])
const patientSearch = ref('')
const slots = ref<TimeSlot[]>([])
let searchTimeout: ReturnType<typeof setTimeout> | null = null

const todayString = new Date().toISOString().slice(0, 10)

const form = reactive({
  patient_id: null as number | null,
  doctor_id: null as number | null,
  appointment_date: todayString,
  appointment_time: '',
  duration_minutes: 30,
  status: 'pending' as 'pending' | 'confirmed',
  reason: '',
})

const selectedDoctor = computed(() => props.doctors.find((d) => d.id === form.doctor_id) ?? null)

function resetForm() {
  patientOptions.value = [...props.initialPatients]
  Object.assign(form, {
    patient_id: null,
    doctor_id: props.doctors[0]?.id ?? null,
    appointment_date: props.initialDate || todayString,
    appointment_time: '',
    duration_minutes: 30,
    status: 'pending',
    reason: '',
  })
  slots.value = []
}

watch(
  () => props.modelValue,
  (isOpen) => {
    if (isOpen) {
      resetForm()
      if (form.doctor_id && form.appointment_date) {
        void loadAvailableSlots()
      }
    }
  },
)

watch(
  () => [form.doctor_id, form.appointment_date, form.duration_minutes],
  () => {
    if (props.modelValue && form.doctor_id && form.appointment_date) {
      void loadAvailableSlots()
    } else {
      slots.value = []
    }
  },
)

watch(patientSearch, (query) => {
  if (!props.modelValue) return
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    void searchPatients(query)
  }, 250)
})

async function searchPatients(query: string) {
  if (!query.trim()) {
    patientOptions.value = [...props.initialPatients]
    return
  }
  busy.searchingPatients = true
  try {
    const response = await api.get<{ data: PatientOption[] }>('/patients', {
      params: { search: query.trim(), status: 'active' },
    })
    patientOptions.value = response.data.data
  } catch {
    // Keep existing options on transient error
  } finally {
    busy.searchingPatients = false
  }
}

async function loadAvailableSlots() {
  if (!form.doctor_id || !form.appointment_date) return
  busy.loadingSlots = true
  try {
    const response = await api.get<{ slots: TimeSlot[] }>('/appointments/available-slots', {
      params: {
        doctor_id: form.doctor_id,
        date: form.appointment_date,
        duration_minutes: form.duration_minutes,
      },
    })
    slots.value = response.data.slots
    if (form.appointment_time) {
      const currentStillAvailable = slots.value.some((s) => s.time === form.appointment_time && s.available)
      if (!currentStillAvailable) {
        form.appointment_time = ''
      }
    }
  } catch {
    slots.value = []
  } finally {
    busy.loadingSlots = false
  }
}

async function submit() {
  busy.saving = true
  try {
    await api.post('/appointments', {
      patient_id: form.patient_id,
      doctor_id: form.doctor_id,
      appointment_date: form.appointment_date,
      appointment_time: form.appointment_time,
      duration_minutes: form.duration_minutes,
      status: form.status,
      reason: form.reason,
    })
    notifications.show('Appointment scheduled successfully.', 'success')
    emit('update:modelValue', false)
    emit('saved')
  } catch (error: any) {
    const firstError = Object.values(error.response?.data?.errors ?? {}).flat()[0]
    notifications.show(typeof firstError === 'string' ? firstError : error.response?.data?.message ?? 'Could not schedule appointment.', 'error')
  } finally {
    busy.saving = false
  }
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="780" scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="xl" class="patient-form-card">
      <div class="dialog-heading">
        <div>
          <p class="eyebrow">APPOINTMENT REGISTRATION</p>
          <h2>Schedule new appointment</h2>
          <p>Select a registered patient, assign an available doctor, and pick an open time slot.</p>
        </div>
        <v-btn icon="mdi-close" variant="text" aria-label="Close" @click="emit('update:modelValue', false)" />
      </div>
      <v-divider />
      <v-card-text class="patient-form-content">
        <v-form @submit.prevent="submit">
          <v-row dense>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="form.patient_id"
                v-model:search="patientSearch"
                :items="patientOptions"
                :loading="busy.searchingPatients"
                item-title="full_name"
                item-value="id"
                label="Registered patient *"
                placeholder="Search by name or patient ID"
                variant="outlined"
                density="comfortable"
                no-filter
                clearable
                required
              >
                <template #item="{ props: itemProps, item }">
                  <v-list-item v-bind="itemProps" :subtitle="`${item.raw.patient_number}${item.raw.phone ? ' · ' + item.raw.phone : ''}`" />
                </template>
              </v-autocomplete>
            </v-col>

            <v-col cols="12" md="6">
              <v-select
                v-model="form.doctor_id"
                :items="doctors"
                item-title="full_name"
                item-value="id"
                label="Assigned doctor *"
                variant="outlined"
                density="comfortable"
                required
              >
                <template #item="{ props: itemProps, item }">
                  <v-list-item
                    v-bind="itemProps"
                    :subtitle="item.raw.specializations.map((s: any) => s.name).join(', ') || item.raw.job_title"
                  />
                </template>
              </v-select>
            </v-col>

            <v-col cols="12" sm="5">
              <v-text-field
                v-model="form.appointment_date"
                type="date"
                :min="todayString"
                label="Appointment date *"
                variant="outlined"
                density="comfortable"
                required
              />
            </v-col>

            <v-col cols="6" sm="3">
              <v-select
                v-model="form.duration_minutes"
                :items="[
                  { title: '15 mins', value: 15 },
                  { title: '30 mins', value: 30 },
                  { title: '45 mins', value: 45 },
                  { title: '60 mins', value: 60 },
                ]"
                label="Duration"
                variant="outlined"
                density="comfortable"
              />
            </v-col>

            <v-col cols="6" sm="4">
              <v-select
                v-model="form.status"
                :items="[
                  { title: 'Pending', value: 'pending' },
                  { title: 'Confirmed', value: 'confirmed' },
                ]"
                label="Initial status"
                variant="outlined"
                density="comfortable"
              />
            </v-col>
          </v-row>

          <div class="slot-picker-section">
            <div class="slot-picker-header">
              <span>Available time slots <template v-if="selectedDoctor">for Dr. {{ selectedDoctor.full_name }}</template></span>
              <v-text-field
                v-model="form.appointment_time"
                type="time"
                label="Custom time"
                variant="outlined"
                density="compact"
                hide-details
                class="custom-time-input"
              />
            </div>

            <div v-if="busy.loadingSlots" class="slot-empty">Loading available slots…</div>
            <div v-else-if="!slots.length" class="slot-empty">
              No configured schedule slots for this doctor on the selected day. You can still enter a custom time if needed.
            </div>
            <div v-else class="slot-grid">
              <v-btn
                v-for="slot in slots"
                :key="slot.time"
                size="small"
                rounded="lg"
                :disabled="!slot.available"
                :color="form.appointment_time === slot.time ? 'primary' : slot.available ? 'default' : undefined"
                :variant="form.appointment_time === slot.time ? 'flat' : 'outlined'"
                class="slot-btn"
                @click="form.appointment_time = slot.time"
              >
                <span>{{ slot.label }}</span>
                <small v-if="!slot.available && slot.reason" class="slot-reason">· {{ slot.reason }}</small>
              </v-btn>
            </div>
          </div>

          <v-textarea
            v-model="form.reason"
            label="Appointment reason / chief complaint *"
            placeholder="e.g., Annual checkup, follow-up consultation, lab results review"
            variant="outlined"
            density="comfortable"
            rows="3"
            class="mt-4"
            required
          />

          <div class="dialog-actions">
            <v-btn variant="text" rounded="lg" @click="emit('update:modelValue', false)">Cancel</v-btn>
            <v-btn
              type="submit"
              color="primary"
              rounded="lg"
              :loading="busy.saving"
              :disabled="!form.patient_id || !form.doctor_id || !form.appointment_date || !form.appointment_time || !form.reason.trim()"
            >
              Schedule appointment
            </v-btn>
          </div>
        </v-form>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.slot-picker-section {
  margin-top: 6px;
  padding: 14px;
  border: 1px solid #e9eef4;
  border-radius: 12px;
  background: #fafcfe;
}
.slot-picker-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  color: #34485c;
  font-size: 12px;
  font-weight: 600;
}
.custom-time-input {
  max-width: 145px;
  background: #fff;
}
.slot-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.slot-btn {
  text-transform: none;
  font-size: 11px;
}
.slot-reason {
  margin-left: 4px;
  opacity: 0.7;
  font-size: 9px;
}
.slot-empty {
  color: #8290a0;
  font-size: 11px;
  padding: 6px 0;
}
</style>


<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import axios from 'axios'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import {
  formatDateTime,
  type ConsultationAppointmentOption,
  type ConsultationDoctorOption,
  type ConsultationPatientOption,
} from '@/types/consultation'

const props = defineProps<{
  modelValue: boolean
  doctors: ConsultationDoctorOption[]
  patients: ConsultationPatientOption[]
  appointments: ConsultationAppointmentOption[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  created: [consultationId: number]
}>()

const notifications = useNotificationsStore()
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})

const form = reactive({
  appointment_id: null as number | null,
  patient_id: null as number | null,
  doctor_id: null as number | null,
  chief_complaint: '',
  symptoms: '',
  clinical_notes: '',
})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      errors.value = {}
      Object.assign(form, {
        appointment_id: null,
        patient_id: null,
        doctor_id: null,
        chief_complaint: '',
        symptoms: '',
        clinical_notes: '',
      })
    }
  },
)

watch(
  () => form.appointment_id,
  (appointmentId) => {
    if (!appointmentId) return
    const appt = props.appointments.find((item) => item.id === appointmentId)
    if (appt) {
      form.patient_id = appt.patient_id
      form.doctor_id = appt.doctor_id
      if (!form.chief_complaint && appt.reason) {
        form.chief_complaint = appt.reason
      }
    }
  },
)

function close() {
  emit('update:modelValue', false)
}

async function submit() {
  saving.value = true
  errors.value = {}
  try {
    const response = await api.post('/consultations', {
      appointment_id: form.appointment_id || null,
      patient_id: form.patient_id,
      doctor_id: form.doctor_id,
      chief_complaint: form.chief_complaint || null,
      symptoms: form.symptoms || null,
      clinical_notes: form.clinical_notes || null,
    })
    notifications.show('Consultation record started.', 'success')
    emit('created', response.data.data.id)
    close()
  } catch (error: unknown) {
    errors.value = axios.isAxiosError(error) ? (error.response?.data?.errors ?? {}) : {}
    notifications.show(getApiErrorMessage(error, 'Could not start consultation.'), 'error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="640" persistent @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="xl" class="pa-2">
      <v-card-title class="d-flex align-center justify-space-between px-5 pt-4">
        <div>
          <span class="text-overline text-primary font-weight-bold">CLINICAL INTAKE</span>
          <h2 class="text-h6 font-weight-bold">Start new consultation</h2>
        </div>
        <v-btn icon="mdi-close" variant="text" @click="close" />
      </v-card-title>

      <v-form @submit.prevent="submit">
        <v-card-text class="px-5 py-3">
          <v-select
            v-model="form.appointment_id"
            label="Link to scheduled appointment (optional)"
            :items="appointments"
            :item-title="(item) => `${item.appointment_number ?? '#' + item.id} · ${item.patient_name} (${formatDateTime(item.scheduled_at)})`"
            item-value="id"
            variant="outlined"
            density="comfortable"
            clearable
            :error-messages="errors.appointment_id"
            class="mb-2"
          />

          <v-row dense>
            <v-col cols="12" sm="6">
              <v-autocomplete
                v-model="form.patient_id"
                label="Registered patient *"
                :items="patients"
                :item-title="(item) => `${item.full_name} (${item.patient_number})`"
                item-value="id"
                variant="outlined"
                density="comfortable"
                :disabled="Boolean(form.appointment_id)"
                :error-messages="errors.patient_id"
                required
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-autocomplete
                v-model="form.doctor_id"
                label="Responsible doctor *"
                :items="doctors"
                :item-title="(item) => `Dr. ${item.full_name}${item.department ? ' · ' + item.department : ''}`"
                item-value="id"
                variant="outlined"
                density="comfortable"
                :disabled="Boolean(form.appointment_id)"
                :error-messages="errors.doctor_id"
                required
              />
            </v-col>
          </v-row>

          <v-text-field
            v-model="form.chief_complaint"
            label="Chief complaint"
            placeholder="Primary reason for visit (e.g., Persistent cough and fever)"
            variant="outlined"
            density="comfortable"
            :error-messages="errors.chief_complaint"
            class="mt-2"
          />

          <v-textarea
            v-model="form.symptoms"
            label="Presenting symptoms"
            placeholder="Describe onset, duration, severity, and associated symptoms"
            variant="outlined"
            density="comfortable"
            rows="2"
            :error-messages="errors.symptoms"
          />

          <v-textarea
            v-model="form.clinical_notes"
            label="Initial intake / clinical notes"
            variant="outlined"
            density="comfortable"
            rows="2"
            :error-messages="errors.clinical_notes"
          />
        </v-card-text>

        <v-card-actions class="px-5 pb-4 justify-end ga-2">
          <v-btn variant="text" @click="close">Cancel</v-btn>
          <v-btn type="submit" color="primary" :loading="saving" rounded="lg">Start consultation</v-btn>
        </v-card-actions>
      </v-form>
    </v-card>
  </v-dialog>
</template>


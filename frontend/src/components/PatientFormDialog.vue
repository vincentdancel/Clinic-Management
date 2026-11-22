<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

export interface PatientRecord {
  id: number
  patient_number: string
  first_name: string
  last_name: string
  gender: string | null
  date_of_birth: string | null
  age: number | null
  phone: string | null
  email: string | null
  address: string | null
  emergency_contact_name: string | null
  emergency_contact_phone: string | null
  status: 'active' | 'archived'
  archived_at?: string | null
  allergies?: AllergyRecord[]
  medical_histories?: MedicalHistoryRecord[]
}

export interface AllergyRecord { id: number; allergen: string; reaction: string | null; severity: string | null; notes: string | null }
export interface MedicalHistoryRecord { id: number; condition_name: string; details: string | null; diagnosed_at: string | null; resolved_at: string | null; recorded_by: number | null }

const props = defineProps<{ modelValue: boolean; patient: PatientRecord | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [patientId: number] }>()
const notifications = useNotificationsStore()
const busy = reactive({ saving: false })
const isEditing = computed(() => props.patient !== null)
const form = reactive({
  first_name: '', last_name: '', gender: null as string | null, date_of_birth: '', phone: '', email: '', address: '',
  emergency_contact_name: '', emergency_contact_phone: '',
})

function resetForm() {
  Object.assign(form, {
    first_name: props.patient?.first_name ?? '',
    last_name: props.patient?.last_name ?? '',
    gender: props.patient?.gender ?? null,
    date_of_birth: props.patient?.date_of_birth ?? '',
    phone: props.patient?.phone ?? '',
    email: props.patient?.email ?? '',
    address: props.patient?.address ?? '',
    emergency_contact_name: props.patient?.emergency_contact_name ?? '',
    emergency_contact_phone: props.patient?.emergency_contact_phone ?? '',
  })
}

watch(() => [props.modelValue, props.patient], ([isOpen]) => {
  if (isOpen) resetForm()
})

async function savePatient() {
  busy.saving = true
  const payload = { ...form, date_of_birth: form.date_of_birth || null, email: form.email || null, gender: form.gender || null }
  try {
    const response = isEditing.value
      ? await api.put(`/patients/${props.patient?.id}`, payload)
      : await api.post('/patients', payload)
    const patient = response.data.data
    notifications.show(isEditing.value ? 'Patient profile updated.' : 'Patient registered successfully.', 'success')
    emit('update:modelValue', false)
    emit('saved', patient.id)
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not save this patient record.'), 'error')
  } finally {
    busy.saving = false
  }
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="760" scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card class="patient-form-card" rounded="xl">
      <div class="dialog-heading"><div><p class="eyebrow">PATIENT RECORD</p><h2>{{ isEditing ? 'Edit patient profile' : 'Register a patient' }}</h2><p>{{ isEditing ? `Patient ID · ${patient?.patient_number}` : 'Add personal and contact information to the clinic directory.' }}</p></div><v-btn icon="mdi-close" variant="text" aria-label="Close dialog" @click="emit('update:modelValue', false)" /></div>
      <v-divider />
      <v-card-text class="patient-form-content"><v-form @submit.prevent="savePatient">
        <h3>Personal information</h3>
        <v-row dense><v-col cols="12" sm="6"><v-text-field v-model="form.first_name" label="First name" variant="outlined" density="comfortable" required /></v-col><v-col cols="12" sm="6"><v-text-field v-model="form.last_name" label="Last name" variant="outlined" density="comfortable" required /></v-col>
          <v-col cols="12" sm="6"><v-select v-model="form.gender" :items="[{ title: 'Female', value: 'female' }, { title: 'Male', value: 'male' }, { title: 'Other', value: 'other' }, { title: 'Prefer not to say', value: 'prefer_not_to_say' }]" label="Gender" variant="outlined" density="comfortable" clearable /></v-col><v-col cols="12" sm="6"><v-text-field v-model="form.date_of_birth" label="Date of birth" type="date" variant="outlined" density="comfortable" /></v-col></v-row>
        <h3 class="form-section-heading">Contact information</h3><v-row dense><v-col cols="12" sm="6"><v-text-field v-model="form.phone" label="Phone number" type="tel" variant="outlined" density="comfortable" /></v-col><v-col cols="12" sm="6"><v-text-field v-model="form.email" label="Email address" type="email" variant="outlined" density="comfortable" /></v-col><v-col cols="12"><v-textarea v-model="form.address" label="Home address" rows="2" auto-grow variant="outlined" density="comfortable" /></v-col></v-row>
        <h3 class="form-section-heading">Emergency contact</h3><v-row dense><v-col cols="12" sm="6"><v-text-field v-model="form.emergency_contact_name" label="Contact name" variant="outlined" density="comfortable" /></v-col><v-col cols="12" sm="6"><v-text-field v-model="form.emergency_contact_phone" label="Contact phone" type="tel" variant="outlined" density="comfortable" /></v-col></v-row>
        <div class="dialog-actions"><v-btn variant="text" @click="emit('update:modelValue', false)">Cancel</v-btn><v-btn type="submit" color="primary" rounded="lg" prepend-icon="mdi-content-save-outline" :loading="busy.saving">{{ isEditing ? 'Save changes' : 'Register patient' }}</v-btn></div>
      </v-form></v-card-text>
    </v-card>
  </v-dialog>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import {
  CONSULTATION_STATUS_META,
  calculateBmi,
  formatDate,
  formatDateTime,
  type ConsultationAuditLog,
  type ConsultationRecord,
  type MedicineOption,
} from '@/types/consultation'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationsStore()

const canManage = computed(() => auth.hasPermission('clinical.manage'))
const canDiagnose = computed(() => auth.hasPermission('clinical.diagnose'))

const loading = ref(true)
const savingClinical = ref(false)
const savingVitals = ref(false)
const savingPrescription = ref(false)
const completing = ref(false)

const activeTab = ref('assessment')
const consultation = ref<ConsultationRecord | null>(null)
const patientHistory = ref<ConsultationRecord[]>([])
const auditLogs = ref<ConsultationAuditLog[]>([])
const medicines = ref<MedicineOption[]>([])

const clinicalForm = reactive({
  chief_complaint: '',
  symptoms: '',
  diagnosis: '',
  treatment_plan: '',
  clinical_notes: '',
  follow_up_date: '',
})

const vitalsForm = reactive({
  systolic_bp: '' as string | number,
  diastolic_bp: '' as string | number,
  temperature_c: '' as string | number,
  heart_rate_bpm: '' as string | number,
  respiratory_rate: '' as string | number,
  oxygen_saturation: '' as string | number,
  height_cm: '' as string | number,
  weight_kg: '' as string | number,
  notes: '',
})

interface PrescriptionDraftItem {
  medicine_name: string
  dosage: string
  frequency: string
  duration: string
  quantity: string | number
  quantity_unit: string
  instructions: string
}

function emptyPrescriptionItem(): PrescriptionDraftItem {
  return {
    medicine_name: '',
    dosage: '',
    frequency: '',
    duration: '',
    quantity: 1,
    quantity_unit: 'tablets',
    instructions: '',
  }
}

const prescriptionForm = reactive({
  prescribed_at: new Date().toISOString().slice(0, 10),
  notes: '',
  items: [emptyPrescriptionItem()] as PrescriptionDraftItem[],
})

const liveBmi = computed(() => calculateBmi(vitalsForm.weight_kg, vitalsForm.height_cm))

const isEditable = computed(() => canManage.value && consultation.value?.status !== 'cancelled')

function syncClinicalForm(record: ConsultationRecord) {
  clinicalForm.chief_complaint = record.chief_complaint ?? ''
  clinicalForm.symptoms = record.symptoms ?? ''
  clinicalForm.diagnosis = record.diagnosis ?? ''
  clinicalForm.treatment_plan = record.treatment_plan ?? ''
  clinicalForm.clinical_notes = record.clinical_notes ?? ''
  clinicalForm.follow_up_date = record.follow_up_date ?? ''
}

async function loadConsultation() {
  loading.value = true
  try {
    const id = route.params.consultationId
    const { data } = await api.get<{ data: ConsultationRecord }>(`/consultations/${id}`)
    consultation.value = data.data
    syncClinicalForm(data.data)

    const [historyRes, auditRes] = await Promise.all([
      api.get<{ consultations: ConsultationRecord[] }>(`/consultations/patients/${data.data.patient_id}/history`),
      api.get<{ data: ConsultationAuditLog[] }>(`/consultations/${id}/audit-logs`),
    ])
    patientHistory.value = historyRes.data.consultations
    auditLogs.value = auditRes.data.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load consultation record.'), 'error')
    void router.replace({ name: 'consultations' })
  } finally {
    loading.value = false
  }
}

async function loadMedicines() {
  try {
    const { data } = await api.get('/consultations/options')
    medicines.value = data.medicines ?? []
  } catch {
    // Non-blocking
  }
}

async function saveClinicalAssessment() {
  if (!consultation.value) return
  savingClinical.value = true
  try {
    const payload: Record<string, unknown> = {
      chief_complaint: clinicalForm.chief_complaint || null,
      symptoms: clinicalForm.symptoms || null,
      clinical_notes: clinicalForm.clinical_notes || null,
    }
    if (canDiagnose.value) {
      payload.diagnosis = clinicalForm.diagnosis || null
      payload.treatment_plan = clinicalForm.treatment_plan || null
      payload.follow_up_date = clinicalForm.follow_up_date || null
    }
    const { data } = await api.put<{ data: ConsultationRecord }>(`/consultations/${consultation.value.id}`, payload)
    consultation.value = data.data
    syncClinicalForm(data.data)
    notifications.show('Clinical record saved.', 'success')
    await refreshAuditLogs()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not save clinical record.'), 'error')
  } finally {
    savingClinical.value = false
  }
}

async function completeConsultation() {
  if (!consultation.value || !window.confirm('Mark this consultation as completed?')) return
  completing.value = true
  try {
    const { data } = await api.post<{ data: ConsultationRecord }>(`/consultations/${consultation.value.id}/complete`)
    consultation.value = data.data
    notifications.show('Consultation marked as completed.', 'success')
    await refreshAuditLogs()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not complete consultation.'), 'error')
  } finally {
    completing.value = false
  }
}

function toNumberOrNull(val: string | number): number | null {
  if (val === '' || val === null || val === undefined) return null
  const num = Number(val)
  return Number.isFinite(num) ? num : null
}

async function recordVitalSigns() {
  if (!consultation.value) return
  savingVitals.value = true
  try {
    await api.post(`/consultations/${consultation.value.id}/vital-signs`, {
      systolic_bp: toNumberOrNull(vitalsForm.systolic_bp),
      diastolic_bp: toNumberOrNull(vitalsForm.diastolic_bp),
      temperature_c: toNumberOrNull(vitalsForm.temperature_c),
      heart_rate_bpm: toNumberOrNull(vitalsForm.heart_rate_bpm),
      respiratory_rate: toNumberOrNull(vitalsForm.respiratory_rate),
      oxygen_saturation: toNumberOrNull(vitalsForm.oxygen_saturation),
      height_cm: toNumberOrNull(vitalsForm.height_cm),
      weight_kg: toNumberOrNull(vitalsForm.weight_kg),
      notes: vitalsForm.notes || null,
    })
    Object.assign(vitalsForm, {
      systolic_bp: '',
      diastolic_bp: '',
      temperature_c: '',
      heart_rate_bpm: '',
      respiratory_rate: '',
      oxygen_saturation: '',
      height_cm: '',
      weight_kg: '',
      notes: '',
    })
    notifications.show('Vital signs recorded.', 'success')
    await loadConsultation()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not record vital signs.'), 'error')
  } finally {
    savingVitals.value = false
  }
}

function addPrescriptionItem() {
  prescriptionForm.items.push(emptyPrescriptionItem())
}

function removePrescriptionItem(index: number) {
  if (prescriptionForm.items.length > 1) {
    prescriptionForm.items.splice(index, 1)
  }
}

async function createPrescription() {
  if (!consultation.value) return
  savingPrescription.value = true
  try {
    const itemsPayload = prescriptionForm.items.map((item) => {
      const matched = medicines.value.find((m) => m.name.toLowerCase() === item.medicine_name.trim().toLowerCase())
      return {
        medicine_id: matched?.id ?? null,
        medicine_name: item.medicine_name.trim(),
        dosage: item.dosage.trim(),
        frequency: item.frequency.trim(),
        duration: item.duration.trim() || null,
        quantity: Number(item.quantity),
        quantity_unit: item.quantity_unit.trim() || matched?.unit || 'units',
        instructions: item.instructions.trim() || null,
      }
    })

    await api.post(`/consultations/${consultation.value.id}/prescriptions`, {
      prescribed_at: prescriptionForm.prescribed_at || null,
      notes: prescriptionForm.notes || null,
      items: itemsPayload,
    })

    prescriptionForm.notes = ''
    prescriptionForm.items = [emptyPrescriptionItem()]
    notifications.show('Prescription created.', 'success')
    await loadConsultation()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not create prescription.'), 'error')
  } finally {
    savingPrescription.value = false
  }
}

async function updatePrescriptionStatus(prescriptionId: number, status: 'active' | 'dispensed' | 'cancelled') {
  if (!consultation.value) return
  try {
    await api.patch(`/consultations/${consultation.value.id}/prescriptions/${prescriptionId}/status`, { status })
    notifications.show(`Prescription marked as ${status}.`, 'success')
    await loadConsultation()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not update prescription.'), 'error')
  }
}

async function refreshAuditLogs() {
  if (!consultation.value) return
  const { data } = await api.get<{ data: ConsultationAuditLog[] }>(`/consultations/${consultation.value.id}/audit-logs`)
  auditLogs.value = data.data
}

watch(
  () => route.params.consultationId,
  () => {
    if (route.name === 'consultation-detail') {
      void loadConsultation()
    }
  },
)

onMounted(() => {
  void loadMedicines()
  void loadConsultation()
})
</script>

<template>
  <section class="consultation-detail-page">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-4">
      <v-btn :to="{ name: 'consultations' }" prepend-icon="mdi-arrow-left" variant="text">
        Consultation register
      </v-btn>
      <div v-if="consultation" class="d-flex align-center ga-2">
        <v-chip :color="CONSULTATION_STATUS_META[consultation.status]?.color ?? 'default'" variant="tonal">
          {{ CONSULTATION_STATUS_META[consultation.status]?.label ?? consultation.status }}
        </v-chip>
        <v-btn
          v-if="canDiagnose && consultation.status === 'in_progress'"
          color="success"
          prepend-icon="mdi-check-circle-outline"
          rounded="lg"
          :loading="completing"
          @click="completeConsultation"
        >
          Complete consultation
        </v-btn>
      </div>
    </div>

    <div v-if="loading" class="d-flex align-center justify-center py-12 ga-3">
      <v-progress-circular indeterminate color="primary" />
      <span class="text-body-2 text-medium-emphasis">Loading medical record…</span>
    </div>

    <v-row v-else-if="consultation" align="start">
      <!-- Left Column: Patient Information, Allergies & Medical History -->
      <v-col cols="12" lg="4">
        <v-card rounded="xl" elevation="0" border class="pa-5 mb-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <span class="text-overline text-primary font-weight-bold">PATIENT PROFILE</span>
            <v-btn
              v-if="consultation.patient"
              :to="{ name: 'patient-detail', params: { patientId: consultation.patient.id } }"
              size="small"
              variant="text"
              color="primary"
              append-icon="mdi-open-in-new"
            >
              Full profile
            </v-btn>
          </div>

          <div v-if="consultation.patient" class="d-flex align-center ga-3 mb-4">
            <v-avatar size="52" color="primary" variant="tonal" class="font-weight-bold">
              {{ consultation.patient.first_name[0] }}{{ consultation.patient.last_name[0] }}
            </v-avatar>
            <div>
              <h2 class="text-h6 font-weight-bold">{{ consultation.patient.full_name }}</h2>
              <p class="text-caption text-medium-emphasis">
                {{ consultation.patient.patient_number }} ·
                {{ consultation.patient.age !== null ? `${consultation.patient.age} yrs` : 'Age N/A' }} ·
                {{ consultation.patient.gender ?? 'Unspecified' }}
              </p>
            </div>
          </div>

          <v-divider class="mb-3" />

          <div v-if="consultation.patient" class="d-flex flex-column ga-2 text-body-2">
            <div class="d-flex justify-space-between">
              <span class="text-medium-emphasis">Date of birth</span>
              <span class="font-weight-medium">{{ formatDate(consultation.patient.date_of_birth) }}</span>
            </div>
            <div class="d-flex justify-space-between">
              <span class="text-medium-emphasis">Phone</span>
              <span class="font-weight-medium">{{ consultation.patient.phone || '—' }}</span>
            </div>
            <div class="d-flex justify-space-between">
              <span class="text-medium-emphasis">Email</span>
              <span class="font-weight-medium">{{ consultation.patient.email || '—' }}</span>
            </div>
            <div class="d-flex justify-space-between">
              <span class="text-medium-emphasis">Emergency contact</span>
              <span class="font-weight-medium text-right">
                {{ consultation.patient.emergency_contact_name || '—' }}
                <template v-if="consultation.patient.emergency_contact_phone">
                  ({{ consultation.patient.emergency_contact_phone }})
                </template>
              </span>
            </div>
          </div>

          <v-divider class="my-4" />

          <div class="text-caption text-medium-emphasis mb-1">ATTENDING PHYSICIAN</div>
          <div v-if="consultation.doctor" class="font-weight-bold">
            Dr. {{ consultation.doctor.full_name }}
          </div>
          <div v-if="consultation.doctor" class="text-caption text-medium-emphasis">
            {{ consultation.doctor.job_title }}
            <template v-if="consultation.doctor.department"> · {{ consultation.doctor.department.name }}</template>
          </div>
          <div v-if="consultation.appointment" class="mt-3 pa-3 rounded-lg bg-grey-lighten-4 text-caption">
            <div class="font-weight-bold">Linked Appointment: {{ consultation.appointment.appointment_number }}</div>
            <div>Scheduled: {{ formatDateTime(consultation.appointment.scheduled_at) }}</div>
          </div>
        </v-card>

        <!-- Allergies Card -->
        <v-card rounded="xl" elevation="0" border class="pa-5 mb-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="d-flex align-center ga-2">
              <v-icon icon="mdi-alert-circle-outline" color="error" />
              <h3 class="text-subtitle-1 font-weight-bold">Known Allergies</h3>
            </div>
            <v-chip size="x-small" :color="consultation.patient?.allergies?.length ? 'error' : 'default'" variant="tonal">
              {{ consultation.patient?.allergies?.length ?? 0 }}
            </v-chip>
          </div>

          <div v-if="consultation.patient?.allergies?.length" class="d-flex flex-column ga-2">
            <v-alert
              v-for="allergy in consultation.patient.allergies"
              :key="allergy.id"
              type="error"
              variant="tonal"
              density="compact"
              rounded="lg"
            >
              <div class="font-weight-bold">{{ allergy.allergen }}</div>
              <div class="text-caption">
                {{ allergy.reaction || 'Reaction not specified' }}
                <template v-if="allergy.severity"> · Severity: {{ allergy.severity }}</template>
              </div>
            </v-alert>
          </div>
          <p v-else class="text-body-2 text-medium-emphasis mb-0">No known allergies recorded.</p>
        </v-card>

        <!-- Medical History Card -->
        <v-card rounded="xl" elevation="0" border class="pa-5">
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="d-flex align-center ga-2">
              <v-icon icon="mdi-clipboard-pulse-outline" color="primary" />
              <h3 class="text-subtitle-1 font-weight-bold">Medical History</h3>
            </div>
            <v-chip size="x-small" variant="tonal">
              {{ consultation.patient?.medical_histories?.length ?? 0 }}
            </v-chip>
          </div>

          <v-list v-if="consultation.patient?.medical_histories?.length" density="compact" class="pa-0">
            <v-list-item
              v-for="item in consultation.patient.medical_histories"
              :key="item.id"
              class="px-0"
            >
              <v-list-item-title class="font-weight-medium text-body-2">{{ item.condition_name }}</v-list-item-title>
              <v-list-item-subtitle class="text-caption">
                {{ item.details || 'No additional details' }}
                <template v-if="item.diagnosed_at"> · Diagnosed {{ formatDate(item.diagnosed_at) }}</template>
                <template v-if="item.resolved_at"> · Resolved {{ formatDate(item.resolved_at) }}</template>
              </v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <p v-else class="text-body-2 text-medium-emphasis mb-0">No prior medical history recorded.</p>
        </v-card>
      </v-col>

      <!-- Right Column: Clinical Record Workspace -->
      <v-col cols="12" lg="8">
        <v-card rounded="xl" elevation="0" border>
          <div class="pa-5 pb-3 d-flex flex-wrap align-center justify-space-between ga-3">
            <div>
              <span class="text-overline text-primary font-weight-bold">{{ consultation.consultation_number }}</span>
              <h1 class="text-h5 font-weight-bold">Medical Record & Consultation Chart</h1>
              <p class="text-caption text-medium-emphasis mb-0">
                Started {{ formatDateTime(consultation.started_at) }}
                <template v-if="consultation.ended_at"> · Completed {{ formatDateTime(consultation.ended_at) }}</template>
              </p>
            </div>
          </div>

          <v-tabs v-model="activeTab" color="primary" class="px-4 border-b">
            <v-tab value="assessment" prepend-icon="mdi-stethoscope">Clinical Assessment</v-tab>
            <v-tab value="vitals" prepend-icon="mdi-heart-pulse">
              Vital Signs & BMI ({{ consultation.vital_signs?.length ?? 0 }})
            </v-tab>
            <v-tab value="prescriptions" prepend-icon="mdi-pill">
              Prescriptions ({{ consultation.prescriptions?.length ?? 0 }})
            </v-tab>
            <v-tab value="history" prepend-icon="mdi-history">
              History & Audit ({{ patientHistory.length }})
            </v-tab>
          </v-tabs>

          <v-window v-model="activeTab" class="pa-5">
            <!-- TAB 1: Clinical Assessment -->
            <v-window-item value="assessment">
              <v-alert
                v-if="!canDiagnose && canManage"
                type="info"
                variant="tonal"
                density="compact"
                rounded="lg"
                class="mb-4"
              >
                You are signed in with nursing/triage access. You can record chief complaints, symptoms, clinical notes, and vital signs. Diagnosis and treatment plans are restricted to attending physicians.
              </v-alert>

              <v-form @submit.prevent="saveClinicalAssessment">
                <v-text-field
                  v-model="clinicalForm.chief_complaint"
                  label="Chief complaint"
                  placeholder="Primary reason for consultation"
                  variant="outlined"
                  density="comfortable"
                  :readonly="!isEditable"
                  class="mb-3"
                />

                <v-textarea
                  v-model="clinicalForm.symptoms"
                  label="Symptoms & clinical presentation"
                  placeholder="Onset, duration, severity, associated symptoms"
                  variant="outlined"
                  density="comfortable"
                  rows="3"
                  :readonly="!isEditable"
                  class="mb-3"
                />

                <v-divider class="my-4" />

                <div class="d-flex align-center justify-space-between mb-3">
                  <h3 class="text-subtitle-1 font-weight-bold">Diagnosis & Treatment Plan</h3>
                  <v-chip v-if="!canDiagnose" size="x-small" color="warning" variant="tonal" prepend-icon="mdi-lock-outline">
                    Doctor only
                  </v-chip>
                </div>

                <v-textarea
                  v-model="clinicalForm.diagnosis"
                  label="Clinical diagnosis"
                  placeholder="Primary and differential diagnosis"
                  variant="outlined"
                  density="comfortable"
                  rows="2"
                  :readonly="!isEditable || !canDiagnose"
                  :disabled="!canDiagnose"
                  class="mb-3"
                />

                <v-textarea
                  v-model="clinicalForm.treatment_plan"
                  label="Treatment plan"
                  placeholder="Therapeutic plan, procedures, patient education, and care instructions"
                  variant="outlined"
                  density="comfortable"
                  rows="3"
                  :readonly="!isEditable || !canDiagnose"
                  :disabled="!canDiagnose"
                  class="mb-3"
                />

                <v-row dense>
                  <v-col cols="12" sm="8">
                    <v-textarea
                      v-model="clinicalForm.clinical_notes"
                      label="Consultation notes"
                      placeholder="Additional clinical observations and progress notes"
                      variant="outlined"
                      density="comfortable"
                      rows="2"
                      :readonly="!isEditable"
                    />
                  </v-col>
                  <v-col cols="12" sm="4">
                    <v-text-field
                      v-model="clinicalForm.follow_up_date"
                      label="Follow-up date"
                      type="date"
                      variant="outlined"
                      density="comfortable"
                      :readonly="!isEditable || !canDiagnose"
                      :disabled="!canDiagnose"
                    />
                  </v-col>
                </v-row>

                <div v-if="isEditable" class="d-flex justify-end mt-2">
                  <v-btn type="submit" color="primary" :loading="savingClinical" prepend-icon="mdi-content-save-outline" rounded="lg">
                    Save clinical assessment
                  </v-btn>
                </div>
              </v-form>
            </v-window-item>

            <!-- TAB 2: Vital Signs & BMI -->
            <v-window-item value="vitals">
              <v-card v-if="isEditable" variant="tonal" color="primary" rounded="xl" class="pa-4 mb-5">
                <h3 class="text-subtitle-1 font-weight-bold mb-3">Record Vital Signs</h3>
                <v-form @submit.prevent="recordVitalSigns">
                  <v-row dense>
                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.systolic_bp"
                        label="Systolic BP (mmHg)"
                        type="number"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>
                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.diastolic_bp"
                        label="Diastolic BP (mmHg)"
                        type="number"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>
                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.temperature_c"
                        label="Temperature (°C)"
                        type="number"
                        step="0.1"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>
                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.heart_rate_bpm"
                        label="Heart rate (bpm)"
                        type="number"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>

                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.respiratory_rate"
                        label="Resp. rate (/min)"
                        type="number"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>
                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.oxygen_saturation"
                        label="SpO₂ (%)"
                        type="number"
                        step="0.1"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>
                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.height_cm"
                        label="Height (cm)"
                        type="number"
                        step="0.1"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>
                    <v-col cols="6" sm="3">
                      <v-text-field
                        v-model="vitalsForm.weight_kg"
                        label="Weight (kg)"
                        type="number"
                        step="0.1"
                        variant="outlined"
                        density="compact"
                        bg-color="surface"
                      />
                    </v-col>
                  </v-row>

                  <div class="d-flex flex-wrap align-center justify-space-between ga-3 mt-2">
                    <div class="d-flex align-center ga-2">
                      <span class="text-body-2 font-weight-medium">Calculated BMI:</span>
                      <v-chip v-if="liveBmi" :color="liveBmi.color" size="small" variant="flat">
                        {{ liveBmi.bmi }} kg/m² · {{ liveBmi.category }}
                      </v-chip>
                      <span v-else class="text-caption text-medium-emphasis">Enter height & weight to calculate BMI</span>
                    </div>

                    <v-btn type="submit" color="primary" :loading="savingVitals" prepend-icon="mdi-plus" rounded="lg">
                      Save vital signs
                    </v-btn>
                  </div>

                  <v-text-field
                    v-model="vitalsForm.notes"
                    label="Vital sign notes (optional)"
                    variant="outlined"
                    density="compact"
                    bg-color="surface"
                    hide-details
                    class="mt-3"
                  />
                </v-form>
              </v-card>

              <h3 class="text-subtitle-1 font-weight-bold mb-3">Recorded Measurements</h3>
              <v-table v-if="consultation.vital_signs?.length" density="comfortable">
                <thead>
                  <tr>
                    <th>Measured At</th>
                    <th>BP</th>
                    <th>Temp</th>
                    <th>HR</th>
                    <th>Height / Weight</th>
                    <th>BMI</th>
                    <th>SpO₂ / RR</th>
                    <th>Recorded By</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="vital in consultation.vital_signs" :key="vital.id">
                    <td class="text-caption">{{ formatDateTime(vital.measured_at) }}</td>
                    <td>{{ vital.blood_pressure || '—' }}</td>
                    <td>{{ vital.temperature_c !== null ? `${vital.temperature_c} °C` : '—' }}</td>
                    <td>{{ vital.heart_rate_bpm !== null ? `${vital.heart_rate_bpm} bpm` : '—' }}</td>
                    <td>
                      <span v-if="vital.height_cm || vital.weight_kg">
                        {{ vital.height_cm ?? '—' }} cm / {{ vital.weight_kg ?? '—' }} kg
                      </span>
                      <span v-else>—</span>
                    </td>
                    <td>
                      <v-chip v-if="vital.bmi !== null" size="x-small" variant="tonal" color="primary">
                        {{ vital.bmi }} ({{ vital.bmi_category }})
                      </v-chip>
                      <span v-else>—</span>
                    </td>
                    <td class="text-caption">
                      {{ vital.oxygen_saturation !== null ? `${vital.oxygen_saturation}%` : '—' }} /
                      {{ vital.respiratory_rate !== null ? `${vital.respiratory_rate}` : '—' }}
                    </td>
                    <td class="text-caption">{{ vital.recorded_by?.name ?? '—' }}</td>
                  </tr>
                </tbody>
              </v-table>
              <p v-else class="text-body-2 text-medium-emphasis">No vital signs recorded for this consultation yet.</p>
            </v-window-item>

            <!-- TAB 3: Prescriptions -->
            <v-window-item value="prescriptions">
              <v-alert
                v-if="!canDiagnose"
                type="info"
                variant="tonal"
                density="compact"
                rounded="lg"
                class="mb-4"
              >
                Only authorized physicians can create or modify prescriptions.
              </v-alert>

              <v-card v-if="canDiagnose && isEditable" variant="outlined" rounded="xl" class="pa-4 mb-5">
                <div class="d-flex align-center justify-space-between mb-3">
                  <h3 class="text-subtitle-1 font-weight-bold">Create Prescription</h3>
                  <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-plus" @click="addPrescriptionItem">
                    Add medicine
                  </v-btn>
                </div>

                <v-form @submit.prevent="createPrescription">
                  <div
                    v-for="(item, index) in prescriptionForm.items"
                    :key="index"
                    class="pa-3 rounded-lg bg-grey-lighten-5 mb-3"
                  >
                    <div class="d-flex align-center justify-space-between mb-2">
                      <span class="text-caption font-weight-bold text-primary">MEDICATION #{{ index + 1 }}</span>
                      <v-btn
                        v-if="prescriptionForm.items.length > 1"
                        icon="mdi-delete-outline"
                        size="x-small"
                        variant="text"
                        color="error"
                        @click="removePrescriptionItem(index)"
                      />
                    </div>
                    <v-row dense>
                      <v-col cols="12" sm="6">
                        <v-combobox
                          v-model="item.medicine_name"
                          :items="medicines.map((m) => m.name)"
                          label="Medicine name *"
                          placeholder="e.g., Amoxicillin 500mg"
                          variant="outlined"
                          density="compact"
                          bg-color="surface"
                          required
                        />
                      </v-col>
                      <v-col cols="6" sm="3">
                        <v-text-field
                          v-model="item.dosage"
                          label="Dosage *"
                          placeholder="e.g., 500 mg"
                          variant="outlined"
                          density="compact"
                          bg-color="surface"
                          required
                        />
                      </v-col>
                      <v-col cols="6" sm="3">
                        <v-text-field
                          v-model="item.frequency"
                          label="Frequency *"
                          placeholder="e.g., Every 8 hours"
                          variant="outlined"
                          density="compact"
                          bg-color="surface"
                          required
                        />
                      </v-col>
                      <v-col cols="6" sm="4">
                        <v-text-field
                          v-model="item.duration"
                          label="Duration"
                          placeholder="e.g., 7 days"
                          variant="outlined"
                          density="compact"
                          bg-color="surface"
                        />
                      </v-col>
                      <v-col cols="3" sm="2">
                        <v-text-field
                          v-model="item.quantity"
                          label="Qty *"
                          type="number"
                          min="1"
                          variant="outlined"
                          density="compact"
                          bg-color="surface"
                          required
                        />
                      </v-col>
                      <v-col cols="3" sm="2">
                        <v-text-field
                          v-model="item.quantity_unit"
                          label="Unit"
                          placeholder="tablets"
                          variant="outlined"
                          density="compact"
                          bg-color="surface"
                        />
                      </v-col>
                      <v-col cols="12" sm="4">
                        <v-text-field
                          v-model="item.instructions"
                          label="Instructions"
                          placeholder="e.g., Take after meals"
                          variant="outlined"
                          density="compact"
                          bg-color="surface"
                        />
                      </v-col>
                    </v-row>
                  </div>

                  <v-row dense align="center">
                    <v-col cols="12" sm="4">
                      <v-text-field
                        v-model="prescriptionForm.prescribed_at"
                        label="Prescribed date"
                        type="date"
                        variant="outlined"
                        density="compact"
                      />
                    </v-col>
                    <v-col cols="12" sm="8">
                      <v-text-field
                        v-model="prescriptionForm.notes"
                        label="Prescription notes"
                        variant="outlined"
                        density="compact"
                      />
                    </v-col>
                  </v-row>

                  <div class="d-flex justify-end">
                    <v-btn type="submit" color="primary" :loading="savingPrescription" prepend-icon="mdi-prescription" rounded="lg">
                      Issue prescription
                    </v-btn>
                  </div>
                </v-form>
              </v-card>

              <h3 class="text-subtitle-1 font-weight-bold mb-3">Issued Prescriptions</h3>
              <div v-if="consultation.prescriptions?.length" class="d-flex flex-column ga-3">
                <v-card
                  v-for="rx in consultation.prescriptions"
                  :key="rx.id"
                  variant="outlined"
                  rounded="xl"
                  class="pa-4"
                >
                  <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-3">
                    <div>
                      <span class="font-weight-bold">Prescription #{{ rx.id }}</span>
                      <span class="text-caption text-medium-emphasis ml-2">
                        Prescribed {{ formatDate(rx.prescribed_at) }}
                        <template v-if="rx.prescriber"> · Dr. {{ rx.prescriber.full_name }}</template>
                      </span>
                    </div>
                    <div class="d-flex align-center ga-2">
                      <v-chip
                        :color="rx.status === 'active' ? 'success' : rx.status === 'dispensed' ? 'primary' : 'error'"
                        size="small"
                        variant="tonal"
                      >
                        {{ rx.status }}
                      </v-chip>
                      <v-btn
                        v-if="canDiagnose && rx.status === 'active'"
                        size="x-small"
                        variant="text"
                        color="error"
                        @click="updatePrescriptionStatus(rx.id, 'cancelled')"
                      >
                        Cancel
                      </v-btn>
                    </div>
                  </div>

                  <v-table density="compact">
                    <thead>
                      <tr>
                        <th>Medicine</th>
                        <th>Dosage</th>
                        <th>Frequency</th>
                        <th>Duration</th>
                        <th>Quantity</th>
                        <th>Instructions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="item in rx.items" :key="item.id">
                        <td class="font-weight-medium">{{ item.medicine_name }}</td>
                        <td>{{ item.dosage }}</td>
                        <td>{{ item.frequency }}</td>
                        <td>{{ item.duration || '—' }}</td>
                        <td>{{ item.quantity }} {{ item.quantity_unit }}</td>
                        <td class="text-caption">{{ item.instructions || '—' }}</td>
                      </tr>
                    </tbody>
                  </v-table>
                  <p v-if="rx.notes" class="text-caption text-medium-emphasis mt-2 mb-0">Notes: {{ rx.notes }}</p>
                </v-card>
              </div>
              <p v-else class="text-body-2 text-medium-emphasis">No prescriptions issued for this consultation.</p>
            </v-window-item>

            <!-- TAB 4: Patient Consultation History & Audit Log -->
            <v-window-item value="history">
              <h3 class="text-subtitle-1 font-weight-bold mb-3">Patient Consultation History</h3>
              <div v-if="patientHistory.length" class="d-flex flex-column ga-3 mb-6">
                <v-card
                  v-for="entry in patientHistory"
                  :key="entry.id"
                  variant="outlined"
                  rounded="xl"
                  class="pa-4"
                  :class="{ 'border-primary': entry.id === consultation.id }"
                >
                  <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-2">
                    <div>
                      <span class="font-weight-bold text-primary">{{ entry.consultation_number }}</span>
                      <span class="text-caption text-medium-emphasis ml-2">{{ formatDateTime(entry.started_at) }}</span>
                    </div>
                    <div class="d-flex align-center ga-2">
                      <v-chip size="x-small" :color="CONSULTATION_STATUS_META[entry.status]?.color ?? 'default'" variant="tonal">
                        {{ CONSULTATION_STATUS_META[entry.status]?.label ?? entry.status }}
                      </v-chip>
                      <v-btn
                        v-if="entry.id !== consultation.id"
                        size="x-small"
                        variant="tonal"
                        color="primary"
                        :to="{ name: 'consultation-detail', params: { consultationId: entry.id } }"
                      >
                        Open
                      </v-btn>
                    </div>
                  </div>
                  <div class="text-body-2">
                    <strong>Doctor:</strong> Dr. {{ entry.doctor?.full_name ?? '—' }}
                  </div>
                  <div class="text-body-2">
                    <strong>Chief complaint:</strong> {{ entry.chief_complaint || '—' }}
                  </div>
                  <div v-if="entry.diagnosis" class="text-body-2">
                    <strong>Diagnosis:</strong> {{ entry.diagnosis }}
                  </div>
                </v-card>
              </div>

              <v-divider class="my-4" />

              <h3 class="text-subtitle-1 font-weight-bold mb-3">Record Audit Trail</h3>
              <v-list v-if="auditLogs.length" density="compact" class="pa-0">
                <v-list-item v-for="log in auditLogs" :key="log.id" class="px-0">
                  <template #prepend>
                    <v-icon icon="mdi-shield-check-outline" color="primary" size="small" />
                  </template>
                  <v-list-item-title class="text-body-2 font-weight-medium">
                    {{ log.event.replaceAll('.', ' ').toUpperCase() }}
                  </v-list-item-title>
                  <v-list-item-subtitle class="text-caption">
                    {{ log.user?.name ?? 'System' }} · {{ formatDateTime(log.created_at) }}
                  </v-list-item-subtitle>
                </v-list-item>
              </v-list>
              <p v-else class="text-body-2 text-medium-emphasis">No audit entries recorded.</p>
            </v-window-item>
          </v-window>
        </v-card>
      </v-col>
    </v-row>
  </section>
</template>


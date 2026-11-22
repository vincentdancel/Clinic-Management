<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PatientFormDialog, { type AllergyRecord, type MedicalHistoryRecord, type PatientRecord } from '@/components/PatientFormDialog.vue'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationsStore()
const patient = ref<PatientRecord | null>(null)
const loading = ref(true)
const editOpen = ref(false)
const allergySaving = ref(false)
const historySaving = ref(false)
const canManage = computed(() => auth.hasPermission('patients.manage'))
const allergy = reactive({ allergen: '', reaction: '', severity: '', notes: '' })
const history = reactive({ condition_name: '', details: '', diagnosed_at: '', resolved_at: '' })

function errorMessage(error: any, fallback: string): string { return error.response?.data?.message ?? fallback }
function formatDate(value: string | null): string { return value ? new Intl.DateTimeFormat('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }).format(new Date(`${value}T00:00:00`)) : '—' }
async function loadPatient() {
  loading.value = true
  try {
    const response = await api.get(`/patients/${route.params.patientId}`)
    patient.value = response.data.data
  } catch (error: any) {
    notifications.show(errorMessage(error, 'Could not load the patient profile.'), 'error')
    void router.replace({ name: 'patients' })
  } finally { loading.value = false }
}

async function addAllergy() {
  if (!patient.value) return
  allergySaving.value = true
  try {
    await api.post(`/patients/${patient.value.id}/allergies`, { ...allergy, reaction: allergy.reaction || null, severity: allergy.severity || null, notes: allergy.notes || null })
    Object.assign(allergy, { allergen: '', reaction: '', severity: '', notes: '' })
    notifications.show('Allergy added.', 'success')
    await loadPatient()
  } catch (error: any) { notifications.show(errorMessage(error, 'Could not add the allergy.'), 'error') } finally { allergySaving.value = false }
}

async function removeAllergy(item: AllergyRecord) {
  if (!patient.value || !confirm(`Remove ${item.allergen} from this profile?`)) return
  try { await api.delete(`/patients/${patient.value.id}/allergies/${item.id}`); notifications.show('Allergy removed.', 'success'); await loadPatient() } catch (error: any) { notifications.show(errorMessage(error, 'Could not remove the allergy.'), 'error') }
}

async function addHistory() {
  if (!patient.value) return
  historySaving.value = true
  try {
    await api.post(`/patients/${patient.value.id}/medical-history`, { ...history, details: history.details || null, diagnosed_at: history.diagnosed_at || null, resolved_at: history.resolved_at || null })
    Object.assign(history, { condition_name: '', details: '', diagnosed_at: '', resolved_at: '' })
    notifications.show('Medical history added.', 'success')
    await loadPatient()
  } catch (error: any) { notifications.show(errorMessage(error, 'Could not add medical history.'), 'error') } finally { historySaving.value = false }
}

async function removeHistory(item: MedicalHistoryRecord) {
  if (!patient.value || !confirm(`Remove ${item.condition_name} from this profile?`)) return
  try { await api.delete(`/patients/${patient.value.id}/medical-history/${item.id}`); notifications.show('Medical history removed.', 'success'); await loadPatient() } catch (error: any) { notifications.show(errorMessage(error, 'Could not remove medical history.'), 'error') }
}

async function archivePatient() {
  if (!patient.value || !confirm(`Archive ${patient.value.first_name} ${patient.value.last_name}'s record?`)) return
  try { await api.delete(`/patients/${patient.value.id}`); notifications.show('Patient record archived.', 'success'); void router.push({ name: 'patients' }) } catch (error: any) { notifications.show(errorMessage(error, 'Could not archive this patient.'), 'error') }
}

onMounted(loadPatient)
</script>

<template>
  <section class="patient-detail-page">
    <div class="patient-detail-top"><v-btn :to="{ name: 'patients' }" prepend-icon="mdi-arrow-left" variant="text">Patient directory</v-btn><div v-if="canManage && patient?.status === 'active'" class="patient-detail-actions"><v-btn prepend-icon="mdi-pencil-outline" variant="outlined" @click="editOpen = true">Edit profile</v-btn><v-btn color="error" prepend-icon="mdi-archive-outline" variant="text" @click="archivePatient">Archive</v-btn></div></div>
    <div v-if="loading" class="patient-profile-loading"><v-progress-circular indeterminate color="primary" /><span>Loading patient profile…</span></div>
    <template v-else-if="patient"><div class="patient-profile-heading"><v-avatar size="62" color="primary" variant="tonal">{{ patient.first_name[0] }}{{ patient.last_name[0] }}</v-avatar><div><p class="eyebrow">{{ patient.patient_number }}</p><h1>{{ patient.first_name }} {{ patient.last_name }}</h1><p>{{ patient.gender?.replaceAll('_', ' ') || 'Gender not specified' }} <span>·</span> {{ patient.age !== null ? `${patient.age} years old` : 'Age not available' }}</p></div><v-chip :color="patient.status === 'active' ? 'success' : 'default'" variant="tonal">{{ patient.status }}</v-chip></div>
      <v-row><v-col cols="12" md="7"><v-card class="patient-profile-card" rounded="xl" elevation="0"><h2>Personal & contact information</h2><div class="patient-info-grid"><div><span>Date of birth</span><b>{{ formatDate(patient.date_of_birth) }}</b></div><div><span>Phone number</span><b>{{ patient.phone || '—' }}</b></div><div><span>Email address</span><b>{{ patient.email || '—' }}</b></div><div><span>Address</span><b>{{ patient.address || '—' }}</b></div></div><v-divider class="my-5" /><h3>Emergency contact</h3><div class="patient-info-grid compact"><div><span>Name</span><b>{{ patient.emergency_contact_name || '—' }}</b></div><div><span>Phone</span><b>{{ patient.emergency_contact_phone || '—' }}</b></div></div></v-card></v-col>
        <v-col cols="12" md="5"><v-card class="patient-profile-card" rounded="xl" elevation="0"><div class="profile-section-title"><div><h2>Allergies</h2><p>Known sensitivities and reactions</p></div></div><div v-if="patient.allergies?.length" class="patient-clinical-list"><div v-for="item in patient.allergies" :key="item.id" class="allergy-item"><v-icon icon="mdi-alert-circle-outline" color="error" size="20" /><div><b>{{ item.allergen }}</b><span>{{ item.reaction || 'No reaction specified' }}<template v-if="item.severity"> · {{ item.severity }}</template></span></div><v-btn v-if="canManage && patient.status === 'active'" icon="mdi-close" size="x-small" variant="text" @click="removeAllergy(item)" /></div></div><p v-else class="patient-empty-copy">No allergies recorded.</p><v-form v-if="canManage && patient.status === 'active'" class="clinical-form" @submit.prevent="addAllergy"><v-text-field v-model="allergy.allergen" label="Allergen" density="compact" variant="outlined" required /><v-select v-model="allergy.severity" label="Severity" :items="['mild', 'moderate', 'severe', 'critical']" density="compact" variant="outlined" clearable /><v-text-field v-model="allergy.reaction" label="Reaction (optional)" density="compact" variant="outlined" /><v-btn type="submit" color="primary" :loading="allergySaving" block>Add allergy</v-btn></v-form></v-card></v-col>
        <v-col cols="12"><v-card class="patient-profile-card" rounded="xl" elevation="0"><div class="profile-section-title"><div><h2>Medical history</h2><p>Conditions relevant to the patient’s care.</p></div></div><v-list v-if="patient.medical_histories?.length" class="medical-history-list" lines="two"><v-list-item v-for="item in patient.medical_histories" :key="item.id"><v-list-item-title>{{ item.condition_name }}</v-list-item-title><v-list-item-subtitle>{{ item.details || 'No details added' }}<template v-if="item.diagnosed_at"> · Diagnosed {{ formatDate(item.diagnosed_at) }}</template><template v-if="item.resolved_at"> · Resolved {{ formatDate(item.resolved_at) }}</template></v-list-item-subtitle><template v-if="canManage && patient.status === 'active'" #append><v-btn icon="mdi-delete-outline" size="small" variant="text" color="error" @click="removeHistory(item)" /></template></v-list-item></v-list><p v-else class="patient-empty-copy">No medical history recorded.</p><v-form v-if="canManage && patient.status === 'active'" class="medical-history-form" @submit.prevent="addHistory"><v-text-field v-model="history.condition_name" label="Condition" density="compact" variant="outlined" required /><v-text-field v-model="history.diagnosed_at" label="Diagnosed on" type="date" density="compact" variant="outlined" /><v-text-field v-model="history.details" label="Notes (optional)" density="compact" variant="outlined" /><v-btn type="submit" color="primary" :loading="historySaving">Add condition</v-btn></v-form></v-card></v-col></v-row>
    </template>
    <PatientFormDialog v-model="editOpen" :patient="patient" @saved="loadPatient" />
  </section>
</template>

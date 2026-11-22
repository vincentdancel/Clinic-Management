<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PatientFormDialog, { type PatientRecord } from '@/components/PatientFormDialog.vue'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

interface PatientPage { data: PatientRecord[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }

const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationsStore()
const canManage = computed(() => auth.hasPermission('patients.manage'))
const patients = ref<PatientRecord[]>([])
const total = ref(0)
const busy = ref(false)
const dialogOpen = ref(false)
const editingPatient = ref<PatientRecord | null>(null)
const search = ref('')
const gender = ref('')
const status = ref('active')
const options = reactive({ page: 1, itemsPerPage: 15 })
const headers = [
  { title: 'PATIENT', key: 'full_name', sortable: false },
  { title: 'PATIENT ID', key: 'patient_number', sortable: false },
  { title: 'CONTACT', key: 'phone', sortable: false },
  { title: 'DATE OF BIRTH / AGE', key: 'date_of_birth', sortable: false },
  { title: 'STATUS', key: 'status', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

async function loadPatients() {
  busy.value = true
  try {
    const response = await api.get<PatientPage>('/patients', { params: { search: search.value || undefined, gender: gender.value || undefined, status: status.value, page: options.page } })
    patients.value = response.data.data
    total.value = response.data.meta.total
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? 'Could not load patient records.', 'error')
  } finally {
    busy.value = false
  }
}

watch([search, gender, status], () => {
  options.page = 1
  void loadPatients()
})
watch(() => options.page, () => void loadPatients())
onMounted(loadPatients)

function registerPatient() { editingPatient.value = null; dialogOpen.value = true }
function editPatient(patient: PatientRecord) { editingPatient.value = patient; dialogOpen.value = true }
function formatDate(value: string | null): string { return value ? new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(`${value}T00:00:00`)) : '—' }

async function archivePatient(patient: PatientRecord) {
  if (!window.confirm(`Archive ${patient.first_name} ${patient.last_name}'s record? It will remain available under the archived filter.`)) return
  try {
    await api.delete(`/patients/${patient.id}`)
    notifications.show('Patient record archived.', 'success')
    await loadPatients()
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? 'Could not archive this patient.', 'error')
  }
}

function savedPatient(patientId: number) {
  void loadPatients()
  if (!editingPatient.value) void router.push({ name: 'patient-detail', params: { patientId } })
}
</script>

<template>
  <section class="patient-list-page">
    <div class="patient-page-heading"><div><p class="eyebrow">PATIENT CARE</p><h1>Patient management</h1><p>Search patient records, review profiles, and manage registrations.</p></div><v-btn v-if="canManage" color="primary" prepend-icon="mdi-account-plus-outline" rounded="lg" @click="registerPatient">Register patient</v-btn></div>

    <v-card class="patient-list-card" rounded="xl" elevation="0">
      <div class="patient-list-toolbar"><div><h2>Patient directory</h2><p>{{ total.toLocaleString() }} {{ total === 1 ? 'record' : 'records' }}</p></div><div class="patient-filters"><v-text-field v-model="search" prepend-inner-icon="mdi-magnify" label="Search name, ID, phone or email" hide-details density="comfortable" variant="outlined" clearable class="patient-search" /><v-select v-model="gender" :items="[{ title: 'All genders', value: '' }, { title: 'Female', value: 'female' }, { title: 'Male', value: 'male' }, { title: 'Other', value: 'other' }]" hide-details density="comfortable" variant="outlined" class="patient-filter-select" /><v-select v-model="status" :items="[{ title: 'Active records', value: 'active' }, { title: 'Archived records', value: 'archived' }, { title: 'All records', value: 'all' }]" hide-details density="comfortable" variant="outlined" class="patient-filter-select" /></div></div>
      <v-data-table-server v-model:page="options.page" :headers="headers" :items="patients" :items-length="total" :items-per-page="options.itemsPerPage" :loading="busy" item-value="id" class="patient-data-table" hover @update:page="options.page = $event">
        <template #item.full_name="{ item }"><RouterLink :to="{ name: 'patient-detail', params: { patientId: item.id } }" class="patient-name-link"><v-avatar size="34" color="primary" variant="tonal">{{ item.first_name[0] }}{{ item.last_name[0] }}</v-avatar><span><b>{{ item.first_name }} {{ item.last_name }}</b><small>{{ item.email || 'No email on file' }}</small></span></RouterLink></template>
        <template #item.patient_number="{ item }"><span class="patient-number">{{ item.patient_number }}</span></template>
        <template #item.phone="{ item }"><span class="patient-contact">{{ item.phone || '—' }}</span></template>
        <template #item.date_of_birth="{ item }"><span class="patient-date-cell">{{ formatDate(item.date_of_birth) }}<small v-if="item.age !== null">{{ item.age }} years old</small></span></template>
        <template #item.status="{ item }"><v-chip size="small" :color="item.status === 'active' ? 'success' : 'default'" variant="tonal" class="patient-status-chip">{{ item.status === 'active' ? 'Active' : 'Archived' }}</v-chip></template>
        <template #item.actions="{ item }"><div class="patient-actions"><v-btn :to="{ name: 'patient-detail', params: { patientId: item.id } }" icon="mdi-eye-outline" variant="text" size="small" aria-label="View patient profile" /><template v-if="canManage && item.status === 'active'"><v-btn icon="mdi-pencil-outline" variant="text" size="small" aria-label="Edit patient" @click="editPatient(item)" /><v-btn icon="mdi-archive-outline" variant="text" size="small" color="error" aria-label="Archive patient" @click="archivePatient(item)" /></template></div></template>
        <template #no-data><div class="patient-empty"><v-icon icon="mdi-account-search-outline" size="32" /><b>No patient records found</b><span>Try changing the search or filters, or register a new patient.</span><v-btn v-if="canManage" color="primary" variant="tonal" prepend-icon="mdi-account-plus-outline" @click="registerPatient">Register patient</v-btn></div></template>
      </v-data-table-server>
    </v-card>

    <PatientFormDialog v-model="dialogOpen" :patient="editingPatient" @saved="savedPatient" />
  </section>
</template>

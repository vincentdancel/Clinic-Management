<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import ConsultationFormDialog from '@/components/ConsultationFormDialog.vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import {
  CONSULTATION_STATUS_META,
  formatDateTime,
  type ConsultationAppointmentOption,
  type ConsultationDoctorOption,
  type ConsultationPatientOption,
  type ConsultationRecord,
} from '@/types/consultation'

interface PageResponse {
  data: ConsultationRecord[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationsStore()

const canManage = computed(() => auth.hasPermission('clinical.manage'))
const canDiagnose = computed(() => auth.hasPermission('clinical.diagnose'))

const consultations = ref<ConsultationRecord[]>([])
const doctors = ref<ConsultationDoctorOption[]>([])
const patients = ref<ConsultationPatientOption[]>([])
const appointments = ref<ConsultationAppointmentOption[]>([])

const total = ref(0)
const busy = ref(false)
const dialogOpen = ref(false)

const search = ref('')
const statusFilter = ref('all')
const doctorFilter = ref<number | null>(null)
const options = reactive({ page: 1, itemsPerPage: 15 })

const headers = [
  { title: 'RECORD #', key: 'consultation_number', sortable: false },
  { title: 'DATE & TIME', key: 'started_at', sortable: false },
  { title: 'PATIENT', key: 'patient', sortable: false },
  { title: 'ATTENDING DOCTOR', key: 'doctor', sortable: false },
  { title: 'CHIEF COMPLAINT / DIAGNOSIS', key: 'chief_complaint', sortable: false },
  { title: 'VITALS', key: 'latest_vital_sign', sortable: false },
  { title: 'STATUS', key: 'status', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

async function loadOptions() {
  try {
    const { data } = await api.get('/consultations/options')
    doctors.value = data.doctors
    patients.value = data.patients
    appointments.value = data.appointments
  } catch {
    // Options are only needed when creating a consultation
  }
}

async function loadConsultations() {
  busy.value = true
  try {
    const { data } = await api.get<PageResponse>('/consultations', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value,
        doctor_id: doctorFilter.value || undefined,
        page: options.page,
        per_page: options.itemsPerPage,
      },
    })
    consultations.value = data.data
    total.value = data.meta.total
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load consultation records.'), 'error')
  } finally {
    busy.value = false
  }
}

function openRecord(item: ConsultationRecord) {
  void router.push({ name: 'consultation-detail', params: { consultationId: item.id } })
}

function onCreated(consultationId: number) {
  void router.push({ name: 'consultation-detail', params: { consultationId } })
}

watch([search, statusFilter, doctorFilter], () => {
  options.page = 1
  void loadConsultations()
})

watch(() => options.page, () => void loadConsultations())

onMounted(() => {
  void loadOptions()
  void loadConsultations()
})
</script>

<template>
  <section class="consultation-list-page">
    <div class="d-flex flex-wrap align-center justify-space-between ga-4 mb-6">
      <div>
        <p class="text-overline text-primary font-weight-bold mb-1">CLINICAL WORKSPACE</p>
        <h1 class="text-h4 font-weight-bold mb-1">Consultation & Medical Records</h1>
        <p class="text-body-2 text-medium-emphasis">
          Record patient consultations, vital signs, diagnoses, treatment plans, and prescriptions.
        </p>
      </div>
      <div class="d-flex align-center ga-3">
        <v-chip v-if="!canDiagnose && canManage" color="info" variant="tonal" prepend-icon="mdi-shield-account-outline">
          Nursing / Triage Access
        </v-chip>
        <v-btn
          v-if="canManage"
          color="primary"
          prepend-icon="mdi-stethoscope"
          rounded="lg"
          size="large"
          @click="dialogOpen = true"
        >
          New consultation
        </v-btn>
      </div>
    </div>

    <v-card rounded="xl" elevation="0" border class="pa-5">
      <div class="d-flex flex-wrap align-center justify-space-between ga-4 mb-4">
        <div>
          <h2 class="text-h6 font-weight-bold">Consultation register</h2>
          <p class="text-caption text-medium-emphasis">
            {{ total.toLocaleString() }} {{ total === 1 ? 'medical record' : 'medical records' }}
          </p>
        </div>

        <div class="d-flex flex-wrap align-center ga-3 w-100 w-lg-auto">
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            label="Search record #, patient, doctor, complaint"
            hide-details
            density="comfortable"
            variant="outlined"
            clearable
            style="flex: 1 1 240px; min-width: 0"
          />
          <v-select
            v-model="doctorFilter"
            :items="doctors"
            :item-title="(d) => `Dr. ${d.full_name}`"
            item-value="id"
            label="Doctor"
            hide-details
            density="comfortable"
            variant="outlined"
            clearable
            style="flex: 1 1 180px; min-width: 0"
          />
          <v-select
            v-model="statusFilter"
            :items="[
              { title: 'All statuses', value: 'all' },
              { title: 'In Progress', value: 'in_progress' },
              { title: 'Completed', value: 'completed' },
              { title: 'Cancelled', value: 'cancelled' },
            ]"
            label="Status"
            hide-details
            density="comfortable"
            variant="outlined"
            style="flex: 1 1 150px; min-width: 0"
          />
        </div>
      </div>

      <v-data-table-server
        v-model:page="options.page"
        :headers="headers"
        :items="consultations"
        :items-length="total"
        :items-per-page="options.itemsPerPage"
        :loading="busy"
        item-value="id"
        hover
        @click:row="(_event: unknown, row: { item: ConsultationRecord }) => openRecord(row.item)"
        @update:page="options.page = $event"
      >
        <template #item.consultation_number="{ item }">
          <span class="font-weight-bold text-primary">{{ item.consultation_number }}</span>
        </template>

        <template #item.started_at="{ item }">
          <span class="text-body-2">{{ formatDateTime(item.started_at) }}</span>
        </template>

        <template #item.patient="{ item }">
          <div v-if="item.patient">
            <div class="font-weight-medium">{{ item.patient.full_name }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ item.patient.patient_number }}
              <template v-if="item.patient.allergies?.length">
                · <span class="text-error font-weight-medium">{{ item.patient.allergies.length }} allergy alert(s)</span>
              </template>
            </div>
          </div>
        </template>

        <template #item.doctor="{ item }">
          <div v-if="item.doctor">
            <div class="font-weight-medium">Dr. {{ item.doctor.full_name }}</div>
            <div class="text-caption text-medium-emphasis">{{ item.doctor.department?.name ?? item.doctor.job_title }}</div>
          </div>
        </template>

        <template #item.chief_complaint="{ item }">
          <div class="text-body-2 font-weight-medium text-truncate" style="max-width: 240px">
            {{ item.chief_complaint || 'No chief complaint recorded' }}
          </div>
          <div v-if="item.diagnosis" class="text-caption text-medium-emphasis text-truncate" style="max-width: 240px">
            Dx: {{ item.diagnosis }}
          </div>
        </template>

        <template #item.latest_vital_sign="{ item }">
          <div v-if="item.latest_vital_sign" class="text-caption">
            <span v-if="item.latest_vital_sign.blood_pressure">BP {{ item.latest_vital_sign.blood_pressure }}</span>
            <span v-if="item.latest_vital_sign.temperature_c"> · {{ item.latest_vital_sign.temperature_c }}°C</span>
            <div v-if="item.latest_vital_sign.bmi !== null" class="text-medium-emphasis">
              BMI {{ item.latest_vital_sign.bmi }} ({{ item.latest_vital_sign.bmi_category }})
            </div>
          </div>
          <span v-else class="text-caption text-medium-emphasis">Not recorded</span>
        </template>

        <template #item.status="{ item }">
          <v-chip
            :color="CONSULTATION_STATUS_META[item.status]?.color ?? 'default'"
            size="small"
            variant="tonal"
          >
            {{ CONSULTATION_STATUS_META[item.status]?.label ?? item.status }}
          </v-chip>
        </template>

        <template #item.actions="{ item }">
          <v-btn
            variant="tonal"
            color="primary"
            size="small"
            rounded="lg"
            prepend-icon="mdi-file-document-outline"
            @click.stop="openRecord(item)"
          >
            Open chart
          </v-btn>
        </template>
      </v-data-table-server>
    </v-card>

    <ConsultationFormDialog
      v-model="dialogOpen"
      :doctors="doctors"
      :patients="patients"
      :appointments="appointments"
      @created="onCreated"
    />
  </section>
</template>


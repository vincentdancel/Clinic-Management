<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppointmentDetailDialog from '@/components/AppointmentDetailDialog.vue'
import AppointmentFormDialog from '@/components/AppointmentFormDialog.vue'
import DoctorScheduleDialog from '@/components/DoctorScheduleDialog.vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import {
  APPOINTMENT_STATUS_META,
  formatAppointmentDate,
  formatAppointmentTime,
  type AppointmentRecord,
  type AppointmentStatus,
  type DoctorOption,
  type PatientOption,
} from '@/types/appointment'

interface PageResponse {
  data: AppointmentRecord[]
  meta: { total: number }
}

const auth = useAuthStore()
const notifications = useNotificationsStore()
const canManage = computed(() => auth.hasPermission('appointments.manage'))

const displayMode = ref<'calendar' | 'list'>('calendar')
const calendarView = ref<'day' | 'week' | 'month'>('week')
const anchorDate = ref(new Date())

const appointments = ref<AppointmentRecord[]>([])
const doctors = ref<DoctorOption[]>([])
const patients = ref<PatientOption[]>([])
const total = ref(0)
const busy = ref(false)

const search = ref('')
const doctorFilter = ref<number | null>(null)
const statusFilter = ref<string>('all')
const options = reactive({ page: 1, itemsPerPage: 15 })

const formDialogOpen = ref(false)
const prefillDate = ref<string | null>(null)
const detailDialogOpen = ref(false)
const selectedAppointment = ref<AppointmentRecord | null>(null)
const scheduleDialogOpen = ref(false)

const headers = [
  { title: 'APPOINTMENT #', key: 'appointment_number', sortable: false },
  { title: 'DATE & TIME', key: 'scheduled_at', sortable: false },
  { title: 'PATIENT', key: 'patient', sortable: false },
  { title: 'DOCTOR', key: 'doctor', sortable: false },
  { title: 'REASON', key: 'reason', sortable: false },
  { title: 'STATUS', key: 'status', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

function toIsoDate(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function addDays(date: Date, days: number): Date {
  const copy = new Date(date)
  copy.setDate(copy.getDate() + days)
  return copy
}

function startOfWeek(date: Date): Date {
  const copy = new Date(date)
  const day = copy.getDay()
  copy.setDate(copy.getDate() - day)
  return copy
}

const dateRange = computed(() => {
  const current = anchorDate.value
  if (calendarView.value === 'day') {
    const iso = toIsoDate(current)
    return { from: iso, to: iso }
  }
  if (calendarView.value === 'week') {
    const start = startOfWeek(current)
    const end = addDays(start, 6)
    return { from: toIsoDate(start), to: toIsoDate(end) }
  }
  const monthStart = new Date(current.getFullYear(), current.getMonth(), 1)
  const monthEnd = new Date(current.getFullYear(), current.getMonth() + 1, 0)
  return {
    from: toIsoDate(startOfWeek(monthStart)),
    to: toIsoDate(addDays(startOfWeek(monthEnd), 6)),
  }
})

const periodLabel = computed(() => {
  const current = anchorDate.value
  if (calendarView.value === 'day') {
    return new Intl.DateTimeFormat('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(current)
  }
  if (calendarView.value === 'week') {
    const start = startOfWeek(current)
    const end = addDays(start, 6)
    const fmt = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric' })
    return `${fmt.format(start)} – ${fmt.format(end)}, ${end.getFullYear()}`
  }
  return new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' }).format(current)
})

const weekDays = computed(() => {
  const start = startOfWeek(anchorDate.value)
  return Array.from({ length: 7 }, (_, index) => {
    const date = addDays(start, index)
    return {
      iso: toIsoDate(date),
      weekday: new Intl.DateTimeFormat('en-PH', { weekday: 'short' }).format(date),
      dayNum: date.getDate(),
      isToday: toIsoDate(date) === toIsoDate(new Date()),
    }
  })
})

const monthCells = computed(() => {
  const start = new Date(dateRange.value.from + 'T00:00:00')
  const currentMonth = anchorDate.value.getMonth()
  const todayIso = toIsoDate(new Date())
  return Array.from({ length: 42 }, (_, index) => {
    const date = addDays(start, index)
    const iso = toIsoDate(date)
    return {
      iso,
      dayNum: date.getDate(),
      inMonth: date.getMonth() === currentMonth,
      isToday: iso === todayIso,
    }
  })
})

const appointmentsByDate = computed(() => {
  const grouped: Record<string, AppointmentRecord[]> = {}
  for (const item of appointments.value) {
    const key = item.appointment_date
    if (!grouped[key]) grouped[key] = []
    grouped[key].push(item)
  }
  return grouped
})

const dailyAppointments = computed(() => appointmentsByDate.value[toIsoDate(anchorDate.value)] ?? [])

function navigatePeriod(direction: -1 | 0 | 1) {
  if (direction === 0) {
    anchorDate.value = new Date()
    return
  }
  const current = anchorDate.value
  if (calendarView.value === 'day') {
    anchorDate.value = addDays(current, direction)
  } else if (calendarView.value === 'week') {
    anchorDate.value = addDays(current, direction * 7)
  } else {
    anchorDate.value = new Date(current.getFullYear(), current.getMonth() + direction, 1)
  }
}

async function loadOptions() {
  try {
    const response = await api.get<{ doctors: DoctorOption[]; patients: PatientOption[] }>('/appointments/options')
    doctors.value = response.data.doctors
    patients.value = response.data.patients
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load appointment options.'), 'error')
  }
}

async function loadAppointments() {
  busy.value = true
  try {
    const isCalendar = displayMode.value === 'calendar'
    const response = await api.get<PageResponse>('/appointments', {
      params: {
        search: search.value || undefined,
        doctor_id: doctorFilter.value || undefined,
        status: statusFilter.value,
        date_from: isCalendar ? dateRange.value.from : undefined,
        date_to: isCalendar ? dateRange.value.to : undefined,
        page: isCalendar ? 1 : options.page,
        per_page: isCalendar ? 200 : options.itemsPerPage,
      },
    })
    appointments.value = response.data.data
    total.value = response.data.meta.total
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load appointments.'), 'error')
  } finally {
    busy.value = false
  }
}

watch([search, doctorFilter, statusFilter], () => {
  options.page = 1
  void loadAppointments()
})

watch([displayMode, calendarView, dateRange], () => {
  void loadAppointments()
})

watch(
  () => options.page,
  () => {
    if (displayMode.value === 'list') void loadAppointments()
  },
)

onMounted(() => {
  void loadOptions()
  void loadAppointments()
})

function openCreate(dateIso?: string) {
  prefillDate.value = dateIso ?? null
  formDialogOpen.value = true
}

function openDetail(appointment: AppointmentRecord) {
  selectedAppointment.value = appointment
  detailDialogOpen.value = true
}

async function quickStatus(appointment: AppointmentRecord, status: AppointmentStatus) {
  try {
    await api.patch(`/appointments/${appointment.id}/status`, { status })
    notifications.show(`Appointment marked as ${APPOINTMENT_STATUS_META[status].label}.`, 'success')
    await loadAppointments()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not update status.'), 'error')
  }
}
</script>

<template>
  <section class="patient-list-page">
    <div class="patient-page-heading">
      <div>
        <p class="eyebrow">CLINIC SCHEDULE</p>
        <h1>Appointment management</h1>
        <p>Schedule patient visits, prevent doctor conflicts, and track appointment statuses.</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <v-btn variant="outlined" color="primary" prepend-icon="mdi-clock-edit-outline" rounded="lg" @click="scheduleDialogOpen = true">
          Doctor schedules
        </v-btn>
        <v-btn v-if="canManage" color="primary" prepend-icon="mdi-calendar-plus" rounded="lg" @click="openCreate()">
          New appointment
        </v-btn>
      </div>
    </div>

    <v-card class="patient-list-card" rounded="xl" elevation="0">
      <div class="appointment-toolbar">
        <div class="d-flex align-center ga-2 flex-wrap">
          <v-btn-toggle v-model="displayMode" mandatory density="compact" color="primary" variant="outlined" rounded="lg">
            <v-btn value="calendar" prepend-icon="mdi-calendar-month-outline">Calendar</v-btn>
            <v-btn value="list" prepend-icon="mdi-format-list-bulleted">List</v-btn>
          </v-btn-toggle>

          <v-btn-toggle
            v-if="displayMode === 'calendar'"
            v-model="calendarView"
            mandatory
            density="compact"
            color="primary"
            variant="outlined"
            rounded="lg"
          >
            <v-btn value="day">Day</v-btn>
            <v-btn value="week">Week</v-btn>
            <v-btn value="month">Month</v-btn>
          </v-btn-toggle>
        </div>

        <div class="patient-filters">
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            label="Search patient, doctor, or ID"
            hide-details
            density="comfortable"
            variant="outlined"
            clearable
            class="patient-search"
          />
          <v-select
            v-model="doctorFilter"
            :items="[{ title: 'All doctors', value: null }, ...doctors.map((d) => ({ title: `Dr. ${d.full_name}`, value: d.id }))]"
            hide-details
            density="comfortable"
            variant="outlined"
            class="patient-filter-select"
          />
          <v-select
            v-model="statusFilter"
            :items="[
              { title: 'All statuses', value: 'all' },
              { title: 'Pending', value: 'pending' },
              { title: 'Confirmed', value: 'confirmed' },
              { title: 'Completed', value: 'completed' },
              { title: 'Cancelled', value: 'cancelled' },
              { title: 'No Show', value: 'no_show' },
            ]"
            hide-details
            density="comfortable"
            variant="outlined"
            class="patient-filter-select"
          />
        </div>
      </div>

      <v-divider />

      <!-- Calendar View -->
      <div v-if="displayMode === 'calendar'" class="calendar-shell">
        <div class="calendar-nav">
          <div class="d-flex align-center ga-2">
            <v-btn icon="mdi-chevron-left" variant="outlined" size="small" rounded="lg" aria-label="Previous" @click="navigatePeriod(-1)" />
            <v-btn variant="outlined" size="small" rounded="lg" @click="navigatePeriod(0)">Today</v-btn>
            <v-btn icon="mdi-chevron-right" variant="outlined" size="small" rounded="lg" aria-label="Next" @click="navigatePeriod(1)" />
            <h2 class="period-title">{{ periodLabel }}</h2>
          </div>
          <div class="status-legend">
            <span v-for="(meta, key) in APPOINTMENT_STATUS_META" :key="key" class="legend-chip">
              <i :style="{ background: meta.text }"></i>
              {{ meta.label }}
            </span>
          </div>
        </div>

        <v-progress-linear v-if="busy" indeterminate color="primary" height="2" />

        <!-- Daily View -->
        <div v-if="calendarView === 'day'" class="day-view">
          <div v-if="!dailyAppointments.length" class="patient-empty">
            <v-icon icon="mdi-calendar-blank-outline" size="34" />
            <b>No appointments scheduled for this day</b>
            <span>Click “New appointment” to book a patient visit.</span>
            <v-btn v-if="canManage" color="primary" variant="tonal" prepend-icon="mdi-calendar-plus" @click="openCreate(toIsoDate(anchorDate))">
              New appointment
            </v-btn>
          </div>
          <div v-else class="day-list">
            <div v-for="item in dailyAppointments" :key="item.id" class="day-card" @click="openDetail(item)">
              <div class="day-time">
                <b>{{ formatAppointmentTime(item.scheduled_at) }}</b>
                <span>{{ item.duration_minutes }} min</span>
              </div>
              <div class="day-body">
                <div class="d-flex align-center ga-2 flex-wrap">
                  <b>{{ item.patient?.full_name ?? 'Unknown Patient' }}</b>
                  <span class="patient-number">{{ item.appointment_number }}</span>
                  <v-chip size="x-small" :color="APPOINTMENT_STATUS_META[item.status].color" variant="tonal">
                    {{ APPOINTMENT_STATUS_META[item.status].label }}
                  </v-chip>
                </div>
                <p>Dr. {{ item.doctor?.full_name ?? 'Unassigned' }} · {{ item.reason || 'No reason provided' }}</p>
              </div>
              <div v-if="canManage" class="day-actions" @click.stop>
                <v-btn
                  v-if="item.status === 'pending'"
                  size="x-small"
                  color="info"
                  variant="tonal"
                  rounded="lg"
                  @click="quickStatus(item, 'confirmed')"
                >
                  Confirm
                </v-btn>
                <v-btn
                  v-if="item.status === 'pending' || item.status === 'confirmed'"
                  size="x-small"
                  color="success"
                  variant="tonal"
                  rounded="lg"
                  @click="quickStatus(item, 'completed')"
                >
                  Complete
                </v-btn>
                <v-btn size="x-small" variant="text" rounded="lg" @click="openDetail(item)">Details</v-btn>
              </div>
            </div>
          </div>
        </div>

        <!-- Weekly View -->
        <div v-else-if="calendarView === 'week'" class="week-grid">
          <div v-for="day in weekDays" :key="day.iso" class="week-col" :class="{ 'is-today': day.isToday }">
            <div class="week-col-header">
              <span>{{ day.weekday }}</span>
              <b>{{ day.dayNum }}</b>
              <v-btn
                v-if="canManage"
                icon="mdi-plus"
                size="x-small"
                variant="text"
                aria-label="Add appointment on day"
                @click="openCreate(day.iso)"
              />
            </div>
            <div class="week-col-body">
              <div v-if="!(appointmentsByDate[day.iso]?.length)" class="week-empty">No visits</div>
              <button
                v-for="item in appointmentsByDate[day.iso] ?? []"
                :key="item.id"
                type="button"
                class="cal-pill"
                :style="{ background: APPOINTMENT_STATUS_META[item.status].bg, color: APPOINTMENT_STATUS_META[item.status].text }"
                @click="openDetail(item)"
              >
                <b>{{ formatAppointmentTime(item.scheduled_at) }}</b>
                <span>{{ item.patient?.full_name }}</span>
                <small>Dr. {{ item.doctor?.last_name }}</small>
              </button>
            </div>
          </div>
        </div>

        <!-- Monthly View -->
        <div v-else class="table-scroll">
          <div class="month-grid">
            <div v-for="name in ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']" :key="name" class="month-head">
              {{ name }}
            </div>
            <div
              v-for="cell in monthCells"
              :key="cell.iso"
              class="month-cell"
              :class="{ 'out-month': !cell.inMonth, 'is-today': cell.isToday }"
            >
              <div class="month-cell-top">
                <button type="button" class="month-day-btn" @click="anchorDate = new Date(cell.iso + 'T00:00:00'); calendarView = 'day'">
                  {{ cell.dayNum }}
                </button>
                <v-btn
                  v-if="canManage"
                  icon="mdi-plus"
                  size="x-small"
                  variant="text"
                  class="month-add-btn"
                  aria-label="Add appointment"
                  @click="openCreate(cell.iso)"
                />
              </div>
              <div class="month-pills">
                <button
                  v-for="item in (appointmentsByDate[cell.iso] ?? []).slice(0, 3)"
                  :key="item.id"
                  type="button"
                  class="month-pill"
                  :style="{ background: APPOINTMENT_STATUS_META[item.status].bg, color: APPOINTMENT_STATUS_META[item.status].text }"
                  @click="openDetail(item)"
                >
                  <b>{{ formatAppointmentTime(item.scheduled_at) }}</b> {{ item.patient?.last_name }}
                </button>
                <button
                  v-if="(appointmentsByDate[cell.iso]?.length ?? 0) > 3"
                  type="button"
                  class="month-more"
                  @click="anchorDate = new Date(cell.iso + 'T00:00:00'); calendarView = 'day'"
                >
                  +{{ (appointmentsByDate[cell.iso]?.length ?? 0) - 3 }} more
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- List View -->
      <v-data-table-server
        v-else
        v-model:page="options.page"
        :headers="headers"
        :items="appointments"
        :items-length="total"
        :items-per-page="options.itemsPerPage"
        :loading="busy"
        item-value="id"
        class="patient-data-table"
        hover
        @update:page="options.page = $event"
      >
        <template #item.appointment_number="{ item }">
          <button type="button" class="patient-number bg-transparent border-0 cursor-pointer" @click="openDetail(item)">
            {{ item.appointment_number ?? `APT-${item.id}` }}
          </button>
        </template>
        <template #item.scheduled_at="{ item }">
          <span class="patient-date-cell">
            <b>{{ formatAppointmentDate(item.scheduled_at) }}</b>
            <small>{{ formatAppointmentTime(item.scheduled_at) }} · {{ item.duration_minutes }}m</small>
          </span>
        </template>
        <template #item.patient="{ item }">
          <span class="patient-date-cell">
            <b>{{ item.patient?.full_name ?? '—' }}</b>
            <small>{{ item.patient?.patient_number }}</small>
          </span>
        </template>
        <template #item.doctor="{ item }">
          <span class="patient-date-cell">
            <b>Dr. {{ item.doctor?.full_name ?? '—' }}</b>
            <small>{{ item.doctor?.specializations?.map((s) => s.name).join(', ') || item.doctor?.job_title }}</small>
          </span>
        </template>
        <template #item.reason="{ item }">
          <span class="patient-contact reason-cell">{{ item.reason || '—' }}</span>
        </template>
        <template #item.status="{ item }">
          <v-chip
            size="small"
            :color="APPOINTMENT_STATUS_META[item.status].color"
            :prepend-icon="APPOINTMENT_STATUS_META[item.status].icon"
            variant="tonal"
            class="patient-status-chip"
          >
            {{ APPOINTMENT_STATUS_META[item.status].label }}
          </v-chip>
        </template>
        <template #item.actions="{ item }">
          <div class="patient-actions">
            <v-btn icon="mdi-eye-outline" variant="text" size="small" aria-label="View appointment" @click="openDetail(item)" />
          </div>
        </template>
        <template #no-data>
          <div class="patient-empty">
            <v-icon icon="mdi-calendar-search" size="32" />
            <b>No appointments found</b>
            <span>Try adjusting your filters or schedule a new appointment.</span>
            <v-btn v-if="canManage" color="primary" variant="tonal" prepend-icon="mdi-calendar-plus" @click="openCreate()">
              New appointment
            </v-btn>
          </div>
        </template>
      </v-data-table-server>
    </v-card>

    <AppointmentFormDialog
      v-model="formDialogOpen"
      :doctors="doctors"
      :initial-patients="patients"
      :initial-date="prefillDate"
      @saved="loadAppointments"
    />

    <AppointmentDetailDialog
      v-model="detailDialogOpen"
      :appointment="selectedAppointment"
      :doctors="doctors"
      :can-manage="canManage"
      @updated="loadAppointments"
    />

    <DoctorScheduleDialog
      v-model="scheduleDialogOpen"
      :doctors="doctors"
      :can-manage="canManage"
      @updated="loadOptions"
    />
  </section>
</template>

<style scoped>
.appointment-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
  padding: 18px 20px;
}
.calendar-shell {
  padding: 16px 20px 20px;
}
.calendar-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 16px;
}
.period-title {
  margin: 0 0 0 8px;
  color: #23364a;
  font-size: 16px;
  font-weight: 700;
}
.status-legend {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.legend-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: #65778a;
  font-size: 11px;
}
.legend-chip i {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.day-list {
  display: grid;
  gap: 10px;
}
.day-card {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  padding: 14px 16px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #fff;
  cursor: pointer;
  transition: border-color 0.15s ease;
}
.day-card:hover {
  border-color: #93c5fd;
}
.day-time {
  min-width: 86px;
  display: flex;
  flex-direction: column;
}
.day-time b {
  color: #1565c0;
  font-size: 13px;
}
.day-time span {
  color: #64748b;
  font-size: 11px;
}
.day-body {
  flex: 1 1 200px;
  min-width: 0;
}
.day-body b {
  color: #1e293b;
  font-size: 14px;
}
.day-body p {
  margin: 4px 0 0;
  color: #475569;
  font-size: 12px;
}
.day-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}
.week-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(120px, 1fr));
  gap: 10px;
  overflow-x: auto;
}
.week-col {
  border: 1px solid #e9eef4;
  border-radius: 12px;
  background: #fafcfe;
  min-height: 260px;
  display: flex;
  flex-direction: column;
}
.week-col.is-today {
  border-color: #197fc0;
  background: #f5fafe;
}
.week-col-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 10px 8px;
  border-bottom: 1px solid #eef2f6;
}
.week-col-header span {
  color: #7c8c9e;
  font-size: 11px;
  font-weight: 600;
}
.week-col-header b {
  color: #23364a;
  font-size: 14px;
}
.week-col-body {
  padding: 8px;
  display: grid;
  gap: 6px;
  align-content: start;
}
.week-empty {
  color: #a0acb9;
  font-size: 11px;
  text-align: center;
  padding: 18px 0;
}
.cal-pill {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  text-align: left;
  padding: 7px 9px;
  border: 0;
  border-radius: 8px;
  cursor: pointer;
  width: 100%;
}
.cal-pill b {
  font-size: 10px;
}
.cal-pill span {
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}
.cal-pill small {
  font-size: 9px;
  opacity: 0.85;
}
.month-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  min-width: 640px;
  border-top: 1px solid #e2e8f0;
  border-left: 1px solid #e2e8f0;
  border-radius: 12px;
  overflow: hidden;
}
.month-head {
  padding: 8px 10px;
  background: #f7f9fc;
  border-right: 1px solid #e9eef4;
  border-bottom: 1px solid #e9eef4;
  color: #7c8c9e;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
}
.month-cell {
  min-height: 112px;
  padding: 6px;
  border-right: 1px solid #e9eef4;
  border-bottom: 1px solid #e9eef4;
  background: #fff;
}
.month-cell.out-month {
  background: #fafbfd;
  opacity: 0.65;
}
.month-cell.is-today {
  background: #f3f9fd;
}
.month-cell-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.month-day-btn {
  border: 0;
  background: transparent;
  color: #2c4054;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  padding: 2px 5px;
  border-radius: 6px;
}
.month-day-btn:hover {
  background: #e9f2f9;
}
.month-add-btn {
  opacity: 0.5;
}
.month-cell:hover .month-add-btn {
  opacity: 1;
}
.month-pills {
  display: grid;
  gap: 3px;
}
.month-pill {
  border: 0;
  border-radius: 5px;
  padding: 3px 5px;
  font-size: 10px;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
}
.month-more {
  border: 0;
  background: transparent;
  color: #197fc0;
  font-size: 10px;
  font-weight: 600;
  text-align: left;
  cursor: pointer;
  padding: 2px 4px;
}
.reason-cell {
  display: inline-block;
  max-width: 240px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>


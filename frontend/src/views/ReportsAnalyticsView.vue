<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import ReportCharts from '@/components/ReportCharts.vue'
import PrintableReportDialog from '@/components/PrintableReportDialog.vue'
import {
  formatReportValue,
  type ReportCatalogItem,
  type ReportOptions,
  type ReportPayload,
  type ReportPeriod,
  type ReportType,
} from '@/types/reports'

const auth = useAuthStore()
const notifications = useNotificationsStore()

const loadingOptions = ref(true)
const loadingReport = ref(false)
const exportingFormat = ref<'pdf' | 'xlsx' | null>(null)
const printDialogOpen = ref(false)

const options = ref<ReportOptions>({
  catalog: [],
  can_export: false,
  doctors: [],
  departments: [],
  medicine_categories: [],
})

const selectedType = ref<ReportType | null>(null)
const report = ref<ReportPayload | null>(null)

const filters = reactive<{
  date_from: string
  date_to: string
  period: ReportPeriod
  search: string
  status: string | null
  doctor_id: number | null
  department_id: number | null
  method: string | null
  category_id: number | null
  gender: string | null
  expiry_scope: string
}>({
  date_from: '',
  date_to: '',
  period: 'monthly',
  search: '',
  status: null,
  doctor_id: null,
  department_id: null,
  method: null,
  category_id: null,
  gender: null,
  expiry_scope: 'expired',
})

const groupedCatalog = computed(() => {
  const groups: Record<string, ReportCatalogItem[]> = {}
  for (const item of options.value.catalog) {
    if (!groups[item.category]) groups[item.category] = []
    groups[item.category]!.push(item)
  }
  return groups
})

const isCurrencyReport = computed(() =>
  ['daily-revenue', 'monthly-revenue', 'payments', 'unpaid-invoices', 'medicine-inventory', 'low-stock', 'expired-medicines'].includes(
    selectedType.value ?? '',
  ),
)

const statusOptions = computed(() => {
  switch (selectedType.value) {
    case 'appointments':
      return ['pending', 'confirmed', 'completed', 'cancelled', 'no_show']
    case 'consultations':
      return ['in_progress', 'completed', 'cancelled']
    case 'employees':
      return ['active', 'on_leave', 'inactive', 'terminated']
    case 'payments':
      return ['completed', 'voided', 'refunded']
    case 'unpaid-invoices':
      return ['unpaid', 'partially_paid']
    default:
      return []
  }
})

const showDateFilters = computed(() => !['medicine-inventory', 'low-stock'].includes(selectedType.value ?? ''))
const showPeriodSelector = computed(() => ['patient-registrations', 'appointments', 'consultations', 'payments'].includes(selectedType.value ?? ''))
const showDoctorFilter = computed(() => ['appointments', 'consultations'].includes(selectedType.value ?? ''))
const showDepartmentFilter = computed(() => ['doctor-activity', 'employees'].includes(selectedType.value ?? ''))
const showMethodFilter = computed(() => ['daily-revenue', 'monthly-revenue', 'payments'].includes(selectedType.value ?? ''))
const showCategoryFilter = computed(() => ['medicine-inventory', 'low-stock', 'expired-medicines'].includes(selectedType.value ?? ''))
const showGenderFilter = computed(() => selectedType.value === 'patient-registrations')
const showExpiryScope = computed(() => selectedType.value === 'expired-medicines')

const tableHeaders = computed(() =>
  (report.value?.columns ?? []).map((col) => ({
    title: col.label,
    key: col.key,
    align: (col.align === 'right' ? 'end' : 'start') as 'start' | 'end',
    sortable: true,
  })),
)

function buildQueryParams() {
  const params: Record<string, string | number> = {
    period: filters.period,
  }
  if (filters.date_from) params.date_from = filters.date_from
  if (filters.date_to) params.date_to = filters.date_to
  if (filters.search.trim()) params.search = filters.search.trim()
  if (filters.status) params.status = filters.status
  if (filters.doctor_id) params.doctor_id = filters.doctor_id
  if (filters.department_id) params.department_id = filters.department_id
  if (filters.method) params.method = filters.method
  if (filters.category_id) params.category_id = filters.category_id
  if (filters.gender) params.gender = filters.gender
  if (showExpiryScope.value && filters.expiry_scope) params.expiry_scope = filters.expiry_scope
  return params
}

function applyQuickRange(preset: 'today' | 'week' | 'month' | 'year' | 'all') {
  const now = new Date()
  const fmt = (d: Date) => d.toISOString().slice(0, 10)
  if (preset === 'all') {
    filters.date_from = ''
    filters.date_to = ''
  } else if (preset === 'today') {
    filters.date_from = fmt(now)
    filters.date_to = fmt(now)
    filters.period = 'daily'
  } else if (preset === 'week') {
    const start = new Date(now)
    start.setDate(now.getDate() - 6)
    filters.date_from = fmt(start)
    filters.date_to = fmt(now)
    filters.period = 'daily'
  } else if (preset === 'month') {
    const start = new Date(now.getFullYear(), now.getMonth(), 1)
    filters.date_from = fmt(start)
    filters.date_to = fmt(now)
    filters.period = 'daily'
  } else if (preset === 'year') {
    const start = new Date(now.getFullYear(), 0, 1)
    filters.date_from = fmt(start)
    filters.date_to = fmt(now)
    filters.period = 'monthly'
  }
  void loadReport()
}

function resetReportFilters() {
  filters.date_from = ''
  filters.date_to = ''
  filters.period = 'monthly'
  filters.search = ''
  filters.status = null
  filters.doctor_id = null
  filters.department_id = null
  filters.method = null
  filters.category_id = null
  filters.gender = null
  filters.expiry_scope = 'expired'
  void loadReport()
}

async function loadOptions() {
  loadingOptions.value = true
  try {
    const { data } = await api.get<{ data: ReportOptions }>('/reports/options')
    options.value = data.data
    if (options.value.catalog.length > 0 && !selectedType.value) {
      selectedType.value = options.value.catalog[0]!.type
      await loadReport()
    }
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? 'Could not load report catalog.', 'error')
  } finally {
    loadingOptions.value = false
  }
}

async function loadReport() {
  if (!selectedType.value) return
  loadingReport.value = true
  try {
    const { data } = await api.get<{ data: ReportPayload }>(`/reports/${selectedType.value}`, {
      params: buildQueryParams(),
    })
    report.value = data.data
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? 'Failed to generate report.', 'error')
  } finally {
    loadingReport.value = false
  }
}

async function exportReport(format: 'pdf' | 'xlsx') {
  if (!selectedType.value) return
  exportingFormat.value = format
  try {
    const response = await api.get(`/reports/${selectedType.value}/export`, {
      params: { ...buildQueryParams(), format },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.setAttribute('download', `clinic-report-${selectedType.value}.${format}`)
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
    notifications.show(`Report exported as ${format.toUpperCase()}.`, 'success')
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? `Could not export ${format.toUpperCase()} report.`, 'error')
  } finally {
    exportingFormat.value = null
  }
}

function selectReport(type: ReportType) {
  selectedType.value = type
  filters.status = null
  filters.doctor_id = null
  filters.department_id = null
  filters.method = null
  filters.category_id = null
  filters.gender = null
  void loadReport()
}

watch(
  () => filters.period,
  () => {
    if (selectedType.value) void loadReport()
  },
)

onMounted(loadOptions)
</script>

<template>
  <section class="reports-page">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">Reports & Analytics</h1>
        <p class="text-body-2 text-medium-emphasis">
          Live operational, clinical, financial, and inventory reports generated from clinic database records.
        </p>
      </div>
      <div v-if="report" class="d-flex flex-wrap ga-2">
        <v-btn
          variant="tonal"
          color="primary"
          prepend-icon="mdi-printer"
          rounded="lg"
          @click="printDialogOpen = true"
        >
          Print Report
        </v-btn>
        <v-btn
          v-if="options.can_export"
          variant="tonal"
          color="error"
          prepend-icon="mdi-file-pdf-box"
          rounded="lg"
          :loading="exportingFormat === 'pdf'"
          @click="exportReport('pdf')"
        >
          Export PDF
        </v-btn>
        <v-btn
          v-if="options.can_export"
          variant="flat"
          color="success"
          prepend-icon="mdi-microsoft-excel"
          rounded="lg"
          :loading="exportingFormat === 'xlsx'"
          @click="exportReport('xlsx')"
        >
          Export Excel
        </v-btn>
      </div>
    </div>

    <v-card v-if="loadingOptions" rounded="xl" elevation="0" class="pa-8 text-center border">
      <v-progress-circular indeterminate color="primary" />
      <p class="text-body-2 text-medium-emphasis mt-3">Loading available reports…</p>
    </v-card>

    <v-alert v-else-if="!options.catalog.length" type="info" variant="tonal" rounded="xl">
      You do not have permission to view any clinic reports. Contact your administrator if you need access.
    </v-alert>

    <v-row v-else dense>
      <v-col cols="12" md="3">
        <v-card rounded="xl" elevation="0" class="pa-3 border">
          <div v-for="(items, category) in groupedCatalog" :key="category" class="mb-3">
            <div class="text-overline text-medium-emphasis px-2 font-weight-bold">{{ category }}</div>
            <v-list density="compact" nav class="pa-0">
              <v-list-item
                v-for="item in items"
                :key="item.type"
                :active="selectedType === item.type"
                color="primary"
                rounded="lg"
                :title="item.title"
                @click="selectReport(item.type)"
              />
            </v-list>
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" md="9">
        <v-card rounded="xl" elevation="0" class="pa-4 mb-4 border">
          <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-3">
            <div>
              <h2 class="text-subtitle-1 font-weight-bold">{{ report?.title ?? 'Select a report' }}</h2>
              <p class="text-caption text-medium-emphasis">{{ report?.description }}</p>
            </div>
            <div v-if="showDateFilters" class="d-flex flex-wrap ga-1">
              <v-btn size="x-small" variant="tonal" @click="applyQuickRange('today')">Today</v-btn>
              <v-btn size="x-small" variant="tonal" @click="applyQuickRange('week')">Last 7 Days</v-btn>
              <v-btn size="x-small" variant="tonal" @click="applyQuickRange('month')">This Month</v-btn>
              <v-btn size="x-small" variant="tonal" @click="applyQuickRange('year')">This Year</v-btn>
              <v-btn size="x-small" variant="text" @click="applyQuickRange('all')">All Time</v-btn>
            </div>
          </div>

          <v-row dense align="center">
            <v-col v-if="showDateFilters" cols="12" sm="6" md="3">
              <v-text-field
                v-model="filters.date_from"
                label="From Date"
                type="date"
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showDateFilters" cols="12" sm="6" md="3">
              <v-text-field
                v-model="filters.date_to"
                label="To Date"
                type="date"
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showPeriodSelector" cols="12" sm="6" md="2">
              <v-select
                v-model="filters.period"
                label="Summary"
                :items="[
                  { title: 'Daily', value: 'daily' },
                  { title: 'Weekly', value: 'weekly' },
                  { title: 'Monthly', value: 'monthly' },
                  { title: 'Yearly', value: 'yearly' },
                ]"
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col cols="12" sm="6" md="4">
              <v-text-field
                v-model="filters.search"
                label="Search records"
                prepend-inner-icon="mdi-magnify"
                density="compact"
                variant="outlined"
                clearable
                hide-details
                @keyup.enter="loadReport"
              />
            </v-col>

            <v-col v-if="statusOptions.length" cols="12" sm="6" md="3">
              <v-select
                v-model="filters.status"
                label="Status"
                :items="statusOptions"
                clearable
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showDoctorFilter" cols="12" sm="6" md="3">
              <v-select
                v-model="filters.doctor_id"
                label="Doctor"
                :items="options.doctors"
                item-title="name"
                item-value="id"
                clearable
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showDepartmentFilter" cols="12" sm="6" md="3">
              <v-select
                v-model="filters.department_id"
                label="Department"
                :items="options.departments"
                item-title="name"
                item-value="id"
                clearable
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showMethodFilter" cols="12" sm="6" md="3">
              <v-select
                v-model="filters.method"
                label="Payment Method"
                :items="[
                  { title: 'Cash', value: 'cash' },
                  { title: 'Card', value: 'card' },
                  { title: 'Bank Transfer', value: 'bank_transfer' },
                  { title: 'Other', value: 'other' },
                ]"
                clearable
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showCategoryFilter" cols="12" sm="6" md="3">
              <v-select
                v-model="filters.category_id"
                label="Category"
                :items="options.medicine_categories"
                item-title="name"
                item-value="id"
                clearable
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showGenderFilter" cols="12" sm="6" md="3">
              <v-select
                v-model="filters.gender"
                label="Gender"
                :items="[
                  { title: 'Male', value: 'male' },
                  { title: 'Female', value: 'female' },
                  { title: 'Other', value: 'other' },
                ]"
                clearable
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>
            <v-col v-if="showExpiryScope" cols="12" sm="6" md="3">
              <v-select
                v-model="filters.expiry_scope"
                label="Expiry Scope"
                :items="[
                  { title: 'Already Expired', value: 'expired' },
                  { title: 'Expiring in 30 Days', value: 'expiring_30d' },
                  { title: 'Expired & Expiring Soon', value: 'all_expired_and_soon' },
                ]"
                density="compact"
                variant="outlined"
                hide-details
              />
            </v-col>

            <v-col cols="12" sm="auto" class="d-flex ga-2">
              <v-btn color="primary" rounded="lg" :loading="loadingReport" @click="loadReport">Apply Filters</v-btn>
              <v-btn variant="text" rounded="lg" @click="resetReportFilters">Reset</v-btn>
            </v-col>
          </v-row>
        </v-card>

        <template v-if="report">
          <v-row dense class="mb-4">
            <v-col v-for="kpi in report.summary" :key="kpi.key" cols="6" sm="3">
              <v-card rounded="xl" elevation="0" class="pa-4 border h-100">
                <div class="text-caption text-uppercase text-medium-emphasis">{{ kpi.label }}</div>
                <div class="text-h6 font-weight-bold mt-1">{{ formatReportValue(kpi.value, kpi.format) }}</div>
              </v-card>
            </v-col>
          </v-row>

          <ReportCharts :trend="report.charts.trend" :breakdown="report.charts.breakdown" :is-currency="isCurrencyReport" />

          <v-card rounded="xl" elevation="0" class="border overflow-hidden">
            <v-data-table
              :headers="tableHeaders"
              :items="report.rows"
              :loading="loadingReport"
              :items-per-page="15"
              density="comfortable"
            >
              <template #item="{ item }">
                <tr>
                  <td
                    v-for="col in report.columns"
                    :key="col.key"
                    :class="{ 'text-right': col.align === 'right' }"
                  >
                    <v-chip
                      v-if="col.format === 'status'"
                      size="small"
                      variant="tonal"
                      :color="
                        ['completed', 'paid', 'active', 'in_stock'].includes(String(item[col.key]))
                          ? 'success'
                          : ['cancelled', 'unpaid', 'out_of_stock', 'expired', 'terminated'].includes(String(item[col.key]))
                            ? 'error'
                            : 'warning'
                      "
                    >
                      {{ formatReportValue(item[col.key], col.format) }}
                    </v-chip>
                    <span v-else>{{ formatReportValue(item[col.key], col.format) }}</span>
                  </td>
                </tr>
              </template>
            </v-data-table>
          </v-card>
        </template>
      </v-col>
    </v-row>

    <PrintableReportDialog
      v-model="printDialogOpen"
      :report="report"
      :prepared-by="auth.user?.name ?? 'Clinic Staff'"
    />
  </section>
</template>

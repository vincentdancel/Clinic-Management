<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

interface PatientRow { id: number; patient_number: string; first_name: string; last_name: string; created_at: string }
interface AppointmentRow {
  id: number; scheduled_at: string; status: string; reason: string | null
  patient: { first_name: string; last_name: string } | null
  doctor: { first_name: string; last_name: string } | null
}
interface RevenueMonth { month: string; label: string; total: number }
interface AppointmentStatus { status: string; total: number }
interface StockAlert { id: number; name: string; sku: string; unit: string; stock: number; reorder_level: number }
interface PaymentStatusItem { status: string; label: string; count: number; total_amount: number }
interface InventoryStatistics {
  total_medicines: number
  active_medicines: number
  total_stock_units: number
  total_inventory_value: number
  low_stock_count: number
  out_of_stock_count: number
  expired_batches_count: number
}
interface DashboardData {
  stats: { total_patients: number; total_doctors: number; total_employees: number; total_appointments: number; today_appointments: number; completed_consultations: number; today_revenue: number; pending_payments: number; low_stock_medicines: number }
  recent_patients: PatientRow[]; upcoming_appointments: AppointmentRow[]; monthly_revenue: RevenueMonth[]
  appointment_statistics: AppointmentStatus[]; low_stock_alerts: StockAlert[]
  payment_status_distribution?: PaymentStatusItem[]
  inventory_statistics?: InventoryStatistics
}

const auth = useAuthStore()
const notifications = useNotificationsStore()
const busy = ref(true)
const data = ref<DashboardData | null>(null)
const dateLabel = new Intl.DateTimeFormat('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(new Date())
const phpCurrency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 })
const compactCurrency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', notation: 'compact', maximumFractionDigits: 1 })

const stats = computed(() => {
  const values = data.value?.stats
  return [
    { label: 'Total patients', value: values?.total_patients ?? 0, icon: 'mdi-account-heart-outline', tone: 'blue', hint: 'Registered patients' },
    { label: 'Total doctors', value: values?.total_doctors ?? 0, icon: 'mdi-stethoscope', tone: 'teal', hint: 'Active care team' },
    { label: 'Total employees', value: values?.total_employees ?? 0, icon: 'mdi-account-group-outline', tone: 'violet', hint: 'Clinic workforce' },
    { label: 'Total appointments', value: values?.total_appointments ?? 0, icon: 'mdi-calendar-check-outline', tone: 'amber', hint: `${values?.today_appointments ?? 0} scheduled today` },
    { label: 'Completed consultations', value: values?.completed_consultations ?? 0, icon: 'mdi-clipboard-check-outline', tone: 'green', hint: 'Completed to date' },
    { label: "Today's revenue", value: phpCurrency.format(values?.today_revenue ?? 0), icon: 'mdi-cash-multiple', tone: 'teal', hint: 'Payments received today' },
    { label: 'Pending payments', value: phpCurrency.format(values?.pending_payments ?? 0), icon: 'mdi-wallet-outline', tone: 'rose', hint: 'Outstanding balance' },
    { label: 'Low stock medicines', value: values?.low_stock_medicines ?? 0, icon: 'mdi-pill', tone: 'orange', hint: 'At or below reorder level' },
  ]
})

const revenue = computed(() => data.value?.monthly_revenue ?? [])
const maxRevenue = computed(() => Math.max(...revenue.value.map((month) => month.total), 1))
const appointmentStatuses = computed(() => data.value?.appointment_statistics ?? [])
const maxAppointments = computed(() => Math.max(...appointmentStatuses.value.map((item) => item.total), 1))
const paymentDistribution = computed(() => data.value?.payment_status_distribution ?? [])
const totalInvoicesCount = computed(() => Math.max(paymentDistribution.value.reduce((sum, item) => sum + item.count, 0), 1))
const inventoryStats = computed(() => data.value?.inventory_statistics)

function monthHeight(total: number): string { return `${Math.max(total === 0 ? 2 : 10, (total / maxRevenue.value) * 100)}%` }
function appointmentLabel(status: string): string { return status.replaceAll('_', ' ').replace(/\b\w/g, (character) => character.toUpperCase()) }
function appointmentTime(value: string): string { return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(new Date(value)) }
function shortDate(value: string): string { return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric' }).format(new Date(value)) }
function patientInitials(patient: PatientRow): string { return `${patient.first_name[0] ?? ''}${patient.last_name[0] ?? ''}`.toUpperCase() }

async function loadDashboard() {
  busy.value = true
  try {
    const response = await api.get<DashboardData>('/dashboard')
    data.value = response.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load live dashboard data. Check the API connection and try again.'), 'error')
  } finally {
    busy.value = false
  }
}

onMounted(loadDashboard)
</script>

<template>
  <section class="dashboard-page">
    <div class="welcome-row">
      <div><p class="eyebrow">{{ dateLabel }}</p><h1>Good {{ new Date().getHours() < 12 ? 'morning' : new Date().getHours() < 17 ? 'afternoon' : 'evening' }}, {{ auth.user?.name?.split(' ')[0] }}.</h1><p class="welcome-subtitle">Here’s what’s happening at your clinic today.</p></div>
      <div class="d-flex ga-2">
        <v-btn v-if="auth.hasPermission('reports.view')" to="/reports" color="primary" prepend-icon="mdi-chart-box-outline" variant="flat" rounded="lg">Reports & Analytics</v-btn>
        <v-btn color="primary" prepend-icon="mdi-refresh" variant="tonal" rounded="lg" :loading="busy" @click="loadDashboard">Refresh data</v-btn>
      </div>
    </div>

    <v-row class="stats-grid" dense>
      <v-col v-for="item in stats" :key="item.label" cols="6" sm="6" lg="3">
        <v-card class="stat-card" rounded="xl" elevation="0">
          <div class="stat-top"><span class="stat-icon" :class="`tone-${item.tone}`"><v-icon :icon="item.icon" size="20" /></span><span class="stat-live"><i></i> Live</span></div>
          <p class="stat-label">{{ item.label }}</p><p class="stat-value" :class="{ 'stat-currency': typeof item.value === 'string' }">{{ busy ? '—' : item.value }}</p><p class="stat-hint">{{ item.hint }}</p>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="dashboard-charts" dense>
      <v-col cols="12" lg="7">
        <v-card class="panel-card revenue-panel" rounded="xl" elevation="0">
          <div class="panel-heading"><div><h2>Revenue overview</h2><p>Collected payments over the last six months</p></div><v-chip size="small" color="primary" variant="tonal">Last 6 months</v-chip></div>
          <div v-if="busy" class="chart-empty">Loading revenue…</div>
          <div v-else-if="!revenue.some((month) => month.total > 0)" class="chart-empty"><v-icon icon="mdi-chart-bar" size="26" /><span>No payment activity recorded for this period.</span></div>
          <div v-else class="revenue-chart">
            <div class="chart-y-labels"><span>{{ compactCurrency.format(maxRevenue) }}</span><span>{{ compactCurrency.format(maxRevenue / 2) }}</span><span>₱0</span></div>
            <div class="chart-bars-area"><div class="chart-gridline top"></div><div class="chart-gridline middle"></div><div class="chart-gridline bottom"></div>
              <div v-for="month in revenue" :key="month.month" class="revenue-bar-column"><div class="revenue-bar-wrap"><div class="revenue-bar" :style="{ height: monthHeight(month.total) }" :title="`${month.label}: ${phpCurrency.format(month.total)}`"></div></div><span class="revenue-month">{{ month.label }}</span></div>
            </div>
          </div>
        </v-card>
      </v-col>
      <v-col cols="12" lg="5">
        <v-card class="panel-card appointment-panel" rounded="xl" elevation="0">
          <div class="panel-heading"><div><h2>Appointment statistics</h2><p>Today’s appointment breakdown</p></div><v-icon icon="mdi-calendar-month-outline" color="primary" /></div>
          <div v-if="busy" class="chart-empty">Loading appointments…</div>
          <div v-else-if="appointmentStatuses.every((item) => item.total === 0)" class="chart-empty"><v-icon icon="mdi-calendar-blank-outline" size="26" /><span>No appointments scheduled today.</span></div>
          <div v-else class="appointment-chart">
            <div class="appointment-total"><strong>{{ data?.stats.today_appointments ?? 0 }}</strong><span>appointments</span></div>
            <div class="appointment-legend"><div v-for="item in appointmentStatuses.filter((item) => item.total > 0)" :key="item.status" class="legend-row"><span class="legend-dot" :class="`status-${item.status}`"></span><span>{{ appointmentLabel(item.status) }}</span><b>{{ item.total }}</b><div class="legend-track"><i :class="`status-${item.status}`" :style="{ width: `${(item.total / maxAppointments) * 100}%` }"></i></div></div></div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="dashboard-lower" dense>
      <v-col cols="12" xl="7">
        <v-card class="panel-card table-panel" rounded="xl" elevation="0">
          <div class="panel-heading"><div><h2>Recent patient registrations</h2><p>Latest patients added to your clinic</p></div><v-btn to="/patients" variant="text" color="primary" append-icon="mdi-arrow-right" size="small">View all</v-btn></div>
          <div v-if="busy" class="table-empty">Loading patient registrations…</div>
          <div v-else-if="!data?.recent_patients.length" class="table-empty"><v-icon icon="mdi-account-plus-outline" size="24" /><span>No patient registrations yet.</span></div>
          <div v-else class="table-scroll"><table class="clinic-table"><thead><tr><th>Patient</th><th>Patient ID</th><th>Registered</th><th>Status</th></tr></thead><tbody><tr v-for="patient in data.recent_patients" :key="patient.id"><td><div class="patient-cell"><v-avatar size="34" color="primary" variant="tonal">{{ patientInitials(patient) }}</v-avatar><b>{{ patient.first_name }} {{ patient.last_name }}</b></div></td><td class="muted-cell">{{ patient.patient_number }}</td><td class="muted-cell">{{ shortDate(patient.created_at) }}</td><td><span class="table-status status-active">Registered</span></td></tr></tbody></table></div>
        </v-card>
      </v-col>
      <v-col cols="12" xl="5">
        <v-card class="panel-card table-panel" rounded="xl" elevation="0">
          <div class="panel-heading"><div><h2>Upcoming appointments</h2><p>Next seven days</p></div><v-btn to="/appointments" variant="text" color="primary" append-icon="mdi-arrow-right" size="small">View all</v-btn></div>
          <div v-if="busy" class="table-empty">Loading appointments…</div>
          <div v-else-if="!data?.upcoming_appointments.length" class="table-empty"><v-icon icon="mdi-calendar-check-outline" size="24" /><span>No upcoming appointments found.</span></div>
          <div v-else class="upcoming-list"><div v-for="appointment in data.upcoming_appointments" :key="appointment.id" class="upcoming-item"><div class="appointment-date"><b>{{ appointmentTime(appointment.scheduled_at) }}</b><span>{{ shortDate(appointment.scheduled_at) }}</span></div><div class="upcoming-person"><b>{{ appointment.patient?.first_name }} {{ appointment.patient?.last_name }}</b><span>Dr. {{ appointment.doctor?.first_name }} {{ appointment.doctor?.last_name }}</span></div><span class="table-status" :class="`status-${appointment.status}`">{{ appointmentLabel(appointment.status) }}</span></div></div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="dashboard-charts mt-2" dense>
      <v-col cols="12" lg="6">
        <v-card class="panel-card" rounded="xl" elevation="0">
          <div class="panel-heading"><div><h2>Payment status distribution</h2><p>Invoice settlement breakdown across the clinic</p></div><v-btn v-if="auth.hasPermission('billing.view')" to="/billing" variant="text" color="primary" size="small">Billing</v-btn></div>
          <div v-if="busy" class="table-empty">Loading payment distribution…</div>
          <div v-else-if="paymentDistribution.every((item) => item.count === 0)" class="table-empty"><v-icon icon="mdi-receipt-text-outline" size="24" /><span>No invoices recorded yet.</span></div>
          <div v-else class="d-flex flex-column ga-3">
            <div v-for="item in paymentDistribution" :key="item.status" class="d-flex flex-column ga-1">
              <div class="d-flex justify-space-between text-body-2">
                <span class="font-weight-medium">{{ item.label }} ({{ item.count }})</span>
                <span class="font-weight-bold">{{ phpCurrency.format(item.total_amount) }}</span>
              </div>
              <v-progress-linear
                :model-value="(item.count / totalInvoicesCount) * 100"
                height="8"
                rounded
                :color="item.status === 'paid' ? 'success' : item.status === 'partially_paid' ? 'warning' : item.status === 'unpaid' ? 'error' : 'grey'"
              />
            </div>
          </div>
        </v-card>
      </v-col>
      <v-col cols="12" lg="6">
        <v-card class="panel-card" rounded="xl" elevation="0">
          <div class="panel-heading"><div><h2>Inventory statistics</h2><p>Active stock valuation, shortages, and expiry alerts</p></div><v-btn v-if="auth.hasPermission('reports.view')" to="/reports" variant="text" color="primary" size="small">Inventory Reports</v-btn></div>
          <div v-if="busy" class="table-empty">Loading inventory statistics…</div>
          <v-row v-else dense>
            <v-col cols="6" sm="4"><v-sheet rounded="lg" border class="pa-3"><div class="text-caption text-medium-emphasis">Active Medicines</div><div class="text-h6 font-weight-bold">{{ inventoryStats?.active_medicines ?? 0 }}</div></v-sheet></v-col>
            <v-col cols="6" sm="4"><v-sheet rounded="lg" border class="pa-3"><div class="text-caption text-medium-emphasis">Total Stock Units</div><div class="text-h6 font-weight-bold">{{ inventoryStats?.total_stock_units ?? 0 }}</div></v-sheet></v-col>
            <v-col cols="12" sm="4"><v-sheet rounded="lg" border class="pa-3"><div class="text-caption text-medium-emphasis">Inventory Value</div><div class="text-h6 font-weight-bold">{{ phpCurrency.format(inventoryStats?.total_inventory_value ?? 0) }}</div></v-sheet></v-col>
            <v-col cols="4"><v-sheet rounded="lg" border class="pa-3"><div class="text-caption text-medium-emphasis">Low Stock</div><div class="text-subtitle-1 font-weight-bold text-warning">{{ inventoryStats?.low_stock_count ?? 0 }}</div></v-sheet></v-col>
            <v-col cols="4"><v-sheet rounded="lg" border class="pa-3"><div class="text-caption text-medium-emphasis">Out of Stock</div><div class="text-subtitle-1 font-weight-bold text-error">{{ inventoryStats?.out_of_stock_count ?? 0 }}</div></v-sheet></v-col>
            <v-col cols="4"><v-sheet rounded="lg" border class="pa-3"><div class="text-caption text-medium-emphasis">Expired Batches</div><div class="text-subtitle-1 font-weight-bold text-error">{{ inventoryStats?.expired_batches_count ?? 0 }}</div></v-sheet></v-col>
          </v-row>
        </v-card>
      </v-col>
    </v-row>

    <v-card class="panel-card stock-panel" rounded="xl" elevation="0">
      <div class="panel-heading"><div><h2>Low-stock medicine alerts</h2><p>Items at or below their reorder level</p></div><v-chip size="small" color="warning" variant="tonal">{{ data?.stats.low_stock_medicines ?? 0 }} items</v-chip></div>
      <div v-if="busy" class="table-empty">Checking current medicine stock…</div>
      <div v-else-if="!data?.low_stock_alerts.length" class="table-empty stock-clear"><v-icon icon="mdi-check-circle-outline" color="success" size="24" /><span>All active medicines are above their reorder levels.</span></div>
      <div v-else class="stock-grid"><div v-for="medicine in data.low_stock_alerts" :key="medicine.id" class="stock-item"><span class="stock-pill"><v-icon icon="mdi-pill" size="18" /></span><div class="stock-name"><b>{{ medicine.name }}</b><span>{{ medicine.sku }}</span></div><div class="stock-quantity"><b>{{ medicine.stock }} {{ medicine.unit }}</b><span>Reorder at {{ medicine.reorder_level }}</span></div><v-icon icon="mdi-alert-circle-outline" color="warning" size="20" /></div></div>
    </v-card>
    <p v-if="!busy && !data" class="dashboard-retry"><v-btn color="primary" variant="tonal" prepend-icon="mdi-refresh" @click="loadDashboard">Retry loading dashboard</v-btn></p>
  </section>
</template>

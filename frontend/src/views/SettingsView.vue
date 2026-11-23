<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { useThemeMode } from '@/composables/useThemeMode'

interface ClinicSettings {
  clinic_name: string
  clinic_tagline: string
  clinic_email: string
  clinic_phone: string
  clinic_address: string
  clinic_website: string
  tax_id: string
  opening_time: string
  closing_time: string
  appointment_duration_minutes: number
  working_days: string[]
  allow_walk_ins: boolean
  currency_code: string
  tax_rate_percent: number
  invoice_prefix: string
  low_stock_threshold: number
  appointment_reminders_enabled: boolean
  low_stock_alerts_enabled: boolean
}

const auth = useAuthStore()
const notifications = useNotificationsStore()
const { isDark } = useThemeMode()

const loading = ref(true)
const saving = ref(false)
const checkingHealth = ref(false)
const apiHealthStatus = ref<'unknown' | 'ok' | 'error'>('unknown')
const activeTab = ref('clinic')

const canManage = computed(
  () => auth.hasPermission('settings.manage') || auth.user?.role?.slug === 'administrator',
)

const form = reactive<ClinicSettings>({
  clinic_name: 'Clinic Care Medical Center',
  clinic_tagline: 'Primary Care & Outpatient Clinic',
  clinic_email: 'contact@cliniccare.local',
  clinic_phone: '+63 2 8123 4567',
  clinic_address: '123 Health Ave., Makati City',
  clinic_website: '',
  tax_id: '',
  opening_time: '08:00',
  closing_time: '17:00',
  appointment_duration_minutes: 30,
  working_days: ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
  allow_walk_ins: true,
  currency_code: 'PHP',
  tax_rate_percent: 0,
  invoice_prefix: 'INV-',
  low_stock_threshold: 10,
  appointment_reminders_enabled: true,
  low_stock_alerts_enabled: true,
})

const durationOptions = [
  { title: '15 minutes', value: 15 },
  { title: '20 minutes', value: 20 },
  { title: '30 minutes', value: 30 },
  { title: '45 minutes', value: 45 },
  { title: '60 minutes', value: 60 },
]

const currencyOptions = [
  { title: 'PHP — Philippine Peso', value: 'PHP' },
  { title: 'USD — US Dollar', value: 'USD' },
  { title: 'EUR — Euro', value: 'EUR' },
  { title: 'SGD — Singapore Dollar', value: 'SGD' },
]

const dayOptions = [
  { label: 'Mon', value: 'mon' },
  { label: 'Tue', value: 'tue' },
  { label: 'Wed', value: 'wed' },
  { label: 'Thu', value: 'thu' },
  { label: 'Fri', value: 'fri' },
  { label: 'Sat', value: 'sat' },
  { label: 'Sun', value: 'sun' },
]

function applySettings(data: Partial<ClinicSettings>) {
  Object.assign(form, {
    ...data,
    working_days: Array.isArray(data.working_days) ? [...data.working_days] : form.working_days,
  })
}

async function loadSettings() {
  loading.value = true
  try {
    const { data } = await api.get<{ settings: ClinicSettings }>('/settings')
    applySettings(data.settings)
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load clinic settings.'), 'error')
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  if (!canManage.value) return
  saving.value = true
  try {
    const { data } = await api.put<{ message: string; settings: ClinicSettings }>('/settings', {
      ...form,
      appointment_duration_minutes: Number(form.appointment_duration_minutes),
      tax_rate_percent: Number(form.tax_rate_percent),
      low_stock_threshold: Number(form.low_stock_threshold),
    })
    applySettings(data.settings)
    notifications.show(data.message ?? 'Clinic settings updated.', 'success')
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Unable to save clinic settings.'), 'error')
  } finally {
    saving.value = false
  }
}

async function checkApiHealth() {
  checkingHealth.value = true
  try {
    const { data } = await api.get<{ status: string }>('/health')
    apiHealthStatus.value = data.status === 'ok' ? 'ok' : 'error'
    notifications.show('API connection is healthy.', 'success')
  } catch {
    apiHealthStatus.value = 'error'
    notifications.show('API health check failed.', 'error')
  } finally {
    checkingHealth.value = false
  }
}

onMounted(loadSettings)
</script>

<template>
  <section class="patient-list-page">
    <div class="patient-page-heading">
      <div>
        <p class="eyebrow">CLINIC WORKSPACE</p>
        <h1>Settings</h1>
        <p>Manage clinic profile, operating hours, billing defaults, and workspace preferences.</p>
      </div>
      <div class="d-flex flex-wrap ga-2">
        <v-btn variant="tonal" color="primary" prepend-icon="mdi-refresh" rounded="lg" :loading="loading" @click="loadSettings">
          Reload
        </v-btn>
        <v-btn
          v-if="canManage"
          color="primary"
          prepend-icon="mdi-content-save-outline"
          rounded="lg"
          :loading="saving"
          :disabled="loading"
          @click="saveSettings"
        >
          Save settings
        </v-btn>
      </div>
    </div>

    <v-alert
      v-if="!loading && !canManage"
      type="info"
      variant="tonal"
      rounded="xl"
      class="mb-5"
      icon="mdi-lock-outline"
    >
      You have read-only access to clinic settings. Contact an administrator to update clinic-wide configuration.
    </v-alert>

    <v-card class="panel-card pa-4 pa-sm-6" rounded="xl" elevation="0">
      <v-tabs v-model="activeTab" color="primary" class="mb-4">
        <v-tab value="clinic" prepend-icon="mdi-hospital-building">Clinic Profile</v-tab>
        <v-tab value="operations" prepend-icon="mdi-clock-outline">Operations & Scheduling</v-tab>
        <v-tab value="billing" prepend-icon="mdi-receipt-text-outline">Billing & Inventory</v-tab>
        <v-tab value="workspace" prepend-icon="mdi-tune-variant">Notifications & Account</v-tab>
      </v-tabs>

      <v-skeleton-loader v-if="loading" type="article, article" />

      <v-form v-else @submit.prevent="saveSettings">
        <v-window v-model="activeTab">
          <v-window-item value="clinic" class="pt-3">
            <v-row>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.clinic_name"
                  label="Clinic name"
                  variant="outlined"
                  :readonly="!canManage"
                  required
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.clinic_tagline"
                  label="Tagline / subtitle"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.clinic_email"
                  label="Contact email"
                  type="email"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.clinic_phone"
                  label="Contact phone"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.clinic_website"
                  label="Website"
                  hint="e.g. https://example.com"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.tax_id"
                  label="Tax ID / Registration number"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12">
                <v-textarea
                  v-model="form.clinic_address"
                  label="Clinic address"
                  rows="2"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
            </v-row>
          </v-window-item>

          <v-window-item value="operations" class="pt-3">
            <v-row>
              <v-col cols="12" sm="6" md="4">
                <v-text-field
                  v-model="form.opening_time"
                  label="Opening time"
                  type="time"
                  variant="outlined"
                  :readonly="!canManage"
                  required
                />
              </v-col>
              <v-col cols="12" sm="6" md="4">
                <v-text-field
                  v-model="form.closing_time"
                  label="Closing time"
                  type="time"
                  variant="outlined"
                  :readonly="!canManage"
                  required
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-select
                  v-model="form.appointment_duration_minutes"
                  :items="durationOptions"
                  label="Default appointment slot"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12">
                <div class="text-subtitle-2 mb-2">Clinic working days</div>
                <v-chip-group v-model="form.working_days" multiple :disabled="!canManage" selected-class="text-primary">
                  <v-chip
                    v-for="day in dayOptions"
                    :key="day.value"
                    :value="day.value"
                    filter
                    variant="outlined"
                  >
                    {{ day.label }}
                  </v-chip>
                </v-chip-group>
              </v-col>
              <v-col cols="12">
                <v-switch
                  v-model="form.allow_walk_ins"
                  label="Allow walk-in patient appointments"
                  color="primary"
                  :disabled="!canManage"
                  hide-details
                />
              </v-col>
            </v-row>
          </v-window-item>

          <v-window-item value="billing" class="pt-3">
            <v-row>
              <v-col cols="12" md="6">
                <v-select
                  v-model="form.currency_code"
                  :items="currencyOptions"
                  label="Default currency"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model.number="form.tax_rate_percent"
                  label="Default tax rate (%)"
                  type="number"
                  min="0"
                  max="100"
                  step="0.5"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.invoice_prefix"
                  label="Invoice number prefix"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model.number="form.low_stock_threshold"
                  label="Default low-stock threshold (units)"
                  type="number"
                  min="1"
                  variant="outlined"
                  :readonly="!canManage"
                />
              </v-col>
            </v-row>
          </v-window-item>

          <v-window-item value="workspace" class="pt-3">
            <v-row>
              <v-col cols="12" md="6">
                <v-card variant="outlined" rounded="xl" class="pa-4 h-100">
                  <div class="text-subtitle-1 font-weight-bold mb-1">Automated Alerts</div>
                  <p class="text-body-2 text-medium-emphasis mb-4">
                    Control clinic-wide reminder and inventory alert preferences.
                  </p>
                  <v-switch
                    v-model="form.appointment_reminders_enabled"
                    label="Enable appointment reminders"
                    color="primary"
                    :disabled="!canManage"
                    class="mb-2"
                    hide-details
                  />
                  <v-switch
                    v-model="form.low_stock_alerts_enabled"
                    label="Enable low-stock medicine alerts"
                    color="primary"
                    :disabled="!canManage"
                    class="mb-2"
                    hide-details
                  />
                  <v-divider class="my-3" />
                  <v-switch
                    v-model="isDark"
                    label="Dark mode"
                    color="primary"
                    hide-details
                  />
                </v-card>
              </v-col>
              <v-col cols="12" md="6">
                <v-card variant="outlined" rounded="xl" class="pa-4 h-100">
                  <div class="text-subtitle-1 font-weight-bold mb-1">Account & System</div>
                  <p class="text-body-2 text-medium-emphasis mb-4">
                    Signed in as <strong>{{ auth.user?.name }}</strong> ({{ auth.user?.role?.name ?? 'Staff' }}).
                  </p>
                  <div class="d-flex flex-wrap ga-2 mb-4">
                    <v-btn to="/profile" variant="tonal" color="primary" prepend-icon="mdi-account-outline" rounded="lg">
                      My profile & password
                    </v-btn>
                    <v-btn
                      v-if="auth.user?.role?.slug === 'administrator' && auth.hasPermission('users.manage')"
                      to="/admin"
                      variant="tonal"
                      color="secondary"
                      prepend-icon="mdi-account-cog-outline"
                      rounded="lg"
                    >
                      User management
                    </v-btn>
                  </div>
                  <v-divider class="my-3" />
                  <div class="d-flex align-center justify-space-between ga-2">
                    <div class="text-body-2">
                      API status:
                      <v-chip
                        size="small"
                        :color="apiHealthStatus === 'ok' ? 'success' : apiHealthStatus === 'error' ? 'error' : 'default'"
                        variant="tonal"
                        class="ml-1"
                      >
                        {{ apiHealthStatus === 'ok' ? 'Online' : apiHealthStatus === 'error' ? 'Offline' : 'Not checked' }}
                      </v-chip>
                    </div>
                    <v-btn size="small" variant="text" color="primary" :loading="checkingHealth" @click="checkApiHealth">
                      Check API
                    </v-btn>
                  </div>
                </v-card>
              </v-col>
            </v-row>
          </v-window-item>
        </v-window>

        <div v-if="canManage" class="d-flex justify-end mt-6">
          <v-btn type="submit" color="primary" prepend-icon="mdi-content-save-outline" rounded="lg" :loading="saving">
            Save settings
          </v-btn>
        </div>
      </v-form>
    </v-card>
  </section>
</template>

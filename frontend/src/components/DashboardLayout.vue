<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { useThemeMode } from '@/composables/useThemeMode'

interface SearchHit {
  id: number
  title: string
  subtitle: string
  to: string
}

interface GlobalSearchResponse {
  patients: SearchHit[]
  appointments: SearchHit[]
  invoices: SearchHit[]
  medicines: SearchHit[]
}

const auth = useAuthStore()
const notices = useNotificationsStore()
const { isDark, toggleTheme } = useThemeMode()
const route = useRoute()
const router = useRouter()
const display = useDisplay()
const drawerOpen = ref(false)
const rail = ref(false)
const quickSearch = ref('')
const quickSearchMenu = ref(false)
const searching = ref(false)
const searchResults = ref<GlobalSearchResponse>({
  patients: [],
  appointments: [],
  invoices: [],
  medicines: [],
})
let searchTimer: ReturnType<typeof setTimeout> | null = null

const isMobile = computed(() => display.smAndDown.value)
const isRail = computed(() => rail.value && !isMobile.value)

const isDrawerVisible = computed({
  get: () => !isMobile.value || drawerOpen.value,
  set: (value: boolean) => {
    if (isMobile.value) {
      drawerOpen.value = value
    }
  },
})

const navigation = [
  { title: 'Dashboard', icon: 'mdi-view-dashboard-outline', to: '/' },
  { title: 'Patient Management', icon: 'mdi-account-heart-outline', to: '/patients', permission: 'patients.view' },
  { title: 'Appointment Management', icon: 'mdi-calendar-clock-outline', to: '/appointments', permission: 'appointments.view' },
  { title: 'Employee Management', icon: 'mdi-account-group-outline', to: '/employees', permission: 'employees.view' },
  { title: 'Consultation & Medical Records', icon: 'mdi-file-document-edit-outline', to: '/consultations', permission: 'clinical.view' },
  { title: 'Billing & Payments', icon: 'mdi-credit-card-outline', to: '/billing', permission: 'billing.view' },
  { title: 'Medicine & Inventory', icon: 'mdi-pill', to: '/inventory', permission: 'inventory.manage' },
  { title: 'Reports & Analytics', icon: 'mdi-chart-box-outline', to: '/reports', permission: 'reports.view' },
  { title: 'User Management', icon: 'mdi-account-cog-outline', to: '/admin', permission: 'users.manage', role: 'administrator' },
  { title: 'Settings', icon: 'mdi-cog-outline', to: '/settings' },
]

const visibleNavigation = computed(() =>
  navigation.filter(
    (item) =>
      (!item.permission || auth.hasPermission(item.permission)) &&
      (!item.role || auth.user?.role?.slug === item.role),
  ),
)

const hasRecordResults = computed(
  () =>
    searchResults.value.patients.length > 0 ||
    searchResults.value.appointments.length > 0 ||
    searchResults.value.invoices.length > 0 ||
    searchResults.value.medicines.length > 0,
)

const isRecordSearch = computed(() => quickSearch.value.trim().length >= 2)

watch(quickSearch, (value) => {
  if (searchTimer) clearTimeout(searchTimer)
  const q = value.trim()
  if (q.length < 2) {
    searching.value = false
    searchResults.value = { patients: [], appointments: [], invoices: [], medicines: [] }
    return
  }
  searching.value = true
  searchTimer = setTimeout(async () => {
    try {
      const { data } = await api.get<GlobalSearchResponse>('/search', { params: { q } })
      searchResults.value = data
    } catch {
      searchResults.value = { patients: [], appointments: [], invoices: [], medicines: [] }
    } finally {
      searching.value = false
    }
  }, 220)
})

const pageTitle = computed(() => (route.meta.title as string) ?? 'Dashboard')

function navigateFromSearch(to: string) {
  quickSearchMenu.value = false
  quickSearch.value = ''
  void router.push(to)
}

async function logout() {
  try {
    await auth.logout()
    notices.show('You have been signed out.', 'success')
    await router.replace('/login')
  } catch {
    notices.show('Could not sign out. Please try again.', 'error')
  }
}
</script>

<template>
  <v-navigation-drawer
    v-model="isDrawerVisible"
    :rail="isRail"
    :temporary="isMobile"
    :permanent="!isMobile"
    width="268"
    rail-width="76"
    class="clinic-sidebar"
  >
    <div class="sidebar-brand">
      <RouterLink to="/" class="brand-lockup" @click="drawerOpen = false">
        <span class="brand-mark">C</span>
        <span v-if="!isRail" class="brand-name">CLINIC <b>CARE</b><small>MANAGEMENT SYSTEM</small></span>
      </RouterLink>
      <v-btn
        v-if="!isMobile"
        :icon="isRail ? 'mdi-chevron-double-right' : 'mdi-chevron-double-left'"
        variant="text"
        size="small"
        class="rail-toggle"
        aria-label="Toggle sidebar"
        @click="rail = !rail"
      />
    </div>
    <div v-if="!isRail" class="sidebar-label">WORKSPACE</div>
    <v-list nav density="compact" class="sidebar-list">
      <v-list-item
        v-for="item in visibleNavigation"
        :key="item.to"
        :to="item.to"
        :active="route.path === item.to || (item.to !== '/' && route.path.startsWith(item.to))"
        :title="item.title"
        :prepend-icon="item.icon"
        rounded="lg"
        class="sidebar-item"
        @click="drawerOpen = false"
      />
    </v-list>
    <div v-if="!isRail" class="sidebar-footer">
      <div class="clinic-online"><span></span> Clinic workspace <b>Online</b></div>
      <div class="sidebar-copyright">Clinic Care · Secure staff portal</div>
    </div>
  </v-navigation-drawer>

  <v-app-bar flat height="68" class="clinic-appbar">
    <v-btn
      v-if="isMobile"
      icon="mdi-menu"
      variant="text"
      class="mobile-menu mr-2"
      aria-label="Open navigation"
      @click="drawerOpen = true"
    />
    <div class="appbar-heading">
      <span class="appbar-eyebrow">CLINIC WORKSPACE</span>
      <span class="appbar-title">{{ pageTitle }}</span>
    </div>
    <v-spacer />
    <v-menu v-model="quickSearchMenu" location="bottom end" :close-on-content-click="false">
      <template #activator="{ props }">
        <v-btn v-bind="props" icon="mdi-magnify" variant="text" aria-label="Global search" title="Search patients, invoices, medicines" class="appbar-icon" />
      </template>
      <v-card min-width="340" max-width="400" rounded="lg" class="pa-3 notification-menu">
        <v-text-field
          v-model="quickSearch"
          prepend-inner-icon="mdi-magnify"
          placeholder="Search patients, invoices, medicines..."
          density="compact"
          variant="outlined"
          clearable
          hide-details
          autofocus
          class="mb-2"
        />
        <v-progress-linear v-if="searching" indeterminate color="primary" height="2" class="mb-2" />
        <v-list v-if="isRecordSearch" density="compact" nav class="pa-0" style="max-height: 360px; overflow-y: auto;">
          <template v-if="searchResults.patients.length">
            <v-list-subheader>Patients</v-list-subheader>
            <v-list-item
              v-for="item in searchResults.patients"
              :key="`patient-${item.id}`"
              prepend-icon="mdi-account-heart-outline"
              :title="item.title"
              :subtitle="item.subtitle"
              rounded="lg"
              @click="navigateFromSearch(item.to)"
            />
          </template>
          <template v-if="searchResults.appointments.length">
            <v-list-subheader>Appointments</v-list-subheader>
            <v-list-item
              v-for="item in searchResults.appointments"
              :key="`appt-${item.id}`"
              prepend-icon="mdi-calendar-clock-outline"
              :title="item.title"
              :subtitle="item.subtitle"
              rounded="lg"
              @click="navigateFromSearch(item.to)"
            />
          </template>
          <template v-if="searchResults.invoices.length">
            <v-list-subheader>Invoices</v-list-subheader>
            <v-list-item
              v-for="item in searchResults.invoices"
              :key="`inv-${item.id}`"
              prepend-icon="mdi-receipt-text-outline"
              :title="item.title"
              :subtitle="item.subtitle"
              rounded="lg"
              @click="navigateFromSearch(item.to)"
            />
          </template>
          <template v-if="searchResults.medicines.length">
            <v-list-subheader>Medicines</v-list-subheader>
            <v-list-item
              v-for="item in searchResults.medicines"
              :key="`med-${item.id}`"
              prepend-icon="mdi-pill"
              :title="item.title"
              :subtitle="item.subtitle"
              rounded="lg"
              @click="navigateFromSearch(item.to)"
            />
          </template>
          <v-list-item v-if="!searching && !hasRecordResults" title="No matching clinic records" disabled />
        </v-list>
        <div v-else class="text-caption text-medium-emphasis px-2 py-2">
          Type at least 2 characters to search patients, appointments, invoices, or medicines.
        </div>
      </v-card>
    </v-menu>
    <v-btn
      :icon="isDark ? 'mdi-weather-sunny' : 'mdi-weather-night'"
      variant="text"
      :aria-label="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
      :title="isDark ? 'Switch to light mode' : 'Switch to dark mode'"
      class="appbar-icon"
      @click="toggleTheme"
    />
    <v-menu location="bottom end" :close-on-content-click="false">
      <template #activator="{ props }">
        <v-btn v-bind="props" icon variant="text" aria-label="Notifications" class="appbar-icon notification-trigger">
          <v-badge dot color="error" offset-x="4" offset-y="4"><v-icon icon="mdi-bell-outline" /></v-badge>
        </v-btn>
      </template>
      <v-card min-width="280" rounded="lg" class="notification-menu">
        <div class="d-flex justify-space-between align-center">
          <b>Notifications</b>
          <span class="notification-count">Today</span>
        </div>
        <v-divider class="my-3" />
        <div class="notification-empty">
          <v-icon icon="mdi-bell-check-outline" size="26" color="primary" />
          <span>You're all caught up.</span>
        </div>
      </v-card>
    </v-menu>
    <v-divider vertical class="appbar-divider mx-2 mx-sm-3" />
    <v-menu location="bottom end">
      <template #activator="{ props }">
        <v-btn v-bind="props" variant="text" class="profile-trigger" rounded="lg">
          <v-avatar size="36" color="primary" class="profile-avatar">{{ auth.user?.name?.slice(0, 1).toUpperCase() }}</v-avatar>
          <span class="profile-copy">
            <b>{{ auth.user?.name }}</b>
            <small>{{ auth.user?.role?.name ?? 'Clinic staff' }}</small>
          </span>
          <v-icon icon="mdi-chevron-down" size="small" />
        </v-btn>
      </template>
      <v-list density="compact" min-width="200" class="profile-menu">
        <v-list-item to="/profile" prepend-icon="mdi-account-outline" title="My profile" />
        <v-list-item
          v-if="auth.user?.role?.slug === 'administrator' && auth.hasPermission('users.manage')"
          to="/admin"
          prepend-icon="mdi-account-cog-outline"
          title="User management"
        />
        <v-divider class="my-1" />
        <v-list-item prepend-icon="mdi-logout" title="Sign out" @click="logout" />
      </v-list>
    </v-menu>
  </v-app-bar>

  <v-main class="clinic-main">
    <div class="page-container">
      <slot />
    </div>
  </v-main>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { usePatientAuthStore } from '@/stores/patientAuth'
import { useNotificationsStore } from '@/stores/notifications'
import { useThemeMode } from '@/composables/useThemeMode'

const patientAuth = usePatientAuthStore()
const notices = useNotificationsStore()
const { isDark, toggleTheme } = useThemeMode()
const route = useRoute()
const router = useRouter()
const display = useDisplay()

const drawerOpen = ref(false)
const rail = ref(false)

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
  { title: 'Patient Dashboard', icon: 'mdi-view-dashboard-outline', to: '/patient/dashboard' },
  { title: 'My Profile', icon: 'mdi-account-outline', to: '/patient/profile', permission: 'patient.profile.view' },
  { title: 'My Appointments', icon: 'mdi-calendar-clock-outline', to: '/patient/appointments', permission: 'patient.appointments.view' },
  { title: 'My Medical Records', icon: 'mdi-clipboard-pulse-outline', to: '/patient/medical-records', permission: 'patient.medical_records.view' },
  { title: 'My Consultations', icon: 'mdi-stethoscope', to: '/patient/consultations', permission: 'patient.consultations.view' },
  { title: 'My Prescriptions', icon: 'mdi-prescription', to: '/patient/prescriptions', permission: 'patient.prescriptions.view' },
  { title: 'My Billing', icon: 'mdi-receipt-text-outline', to: '/patient/billing', permission: 'patient.billing.view' },
  { title: 'Account Settings', icon: 'mdi-cog-outline', to: '/patient/settings', permission: 'patient.account.manage' },
]

const visibleNavigation = computed(() =>
  navigation.filter((item) => !item.permission || patientAuth.hasPermission(item.permission)),
)

const pageTitle = computed(() => (route.meta.title as string) ?? 'Patient Dashboard')

async function logout() {
  try {
    await patientAuth.logout()
    notices.show('You have been signed out.', 'success')
    await router.replace('/patient/login')
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
      <RouterLink to="/patient/dashboard" class="brand-lockup" @click="drawerOpen = false">
        <span class="brand-mark">C</span>
        <span v-if="!isRail" class="brand-name">CLINIC <b>CARE</b><small>PATIENT PORTAL</small></span>
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
    <div v-if="!isRail" class="sidebar-label">MY HEALTH</div>
    <v-list nav density="compact" class="sidebar-list">
      <v-list-item
        v-for="item in visibleNavigation"
        :key="item.to"
        :to="item.to"
        :active="route.path === item.to || route.path.startsWith(`${item.to}/`)"
        :title="item.title"
        :prepend-icon="item.icon"
        rounded="lg"
        class="sidebar-item"
        @click="drawerOpen = false"
      />
      <v-list-item
        title="Logout"
        prepend-icon="mdi-logout"
        rounded="lg"
        class="sidebar-item mt-2"
        @click="logout"
      />
    </v-list>
    <div v-if="!isRail" class="sidebar-footer">
      <div class="clinic-online"><span></span> Patient portal <b>Active</b></div>
      <div class="sidebar-copyright">{{ patientAuth.patient?.patient_number || 'Clinic Care' }}</div>
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
      <span class="appbar-eyebrow">PATIENT PORTAL</span>
      <span class="appbar-title">{{ pageTitle }}</span>
    </div>
    <v-spacer />
    <v-btn
      :icon="isDark ? 'mdi-weather-sunny' : 'mdi-weather-night'"
      variant="text"
      class="appbar-icon mr-1"
      aria-label="Toggle theme"
      @click="toggleTheme"
    />
    <v-menu location="bottom end">
      <template #activator="{ props }">
        <v-btn v-bind="props" variant="text" class="text-none">
          <v-avatar size="32" color="primary" variant="tonal" class="mr-2">
            {{ patientAuth.patient?.first_name?.[0] || 'P' }}{{ patientAuth.patient?.last_name?.[0] || '' }}
          </v-avatar>
          <span class="d-none d-sm-inline text-body-2 font-weight-medium">{{ patientAuth.patient?.full_name || patientAuth.user?.name }}</span>
          <v-icon icon="mdi-chevron-down" size="18" class="ml-1" />
        </v-btn>
      </template>
      <v-list density="compact" min-width="200">
        <v-list-item to="/patient/profile" prepend-icon="mdi-account-outline" title="My Profile" />
        <v-list-item to="/patient/settings" prepend-icon="mdi-cog-outline" title="Account Settings" />
        <v-divider class="my-1" />
        <v-list-item prepend-icon="mdi-logout" title="Logout" @click="logout" />
      </v-list>
    </v-menu>
  </v-app-bar>

  <v-main class="clinic-main">
    <v-container fluid class="pa-4 pa-md-6">
      <slot />
    </v-container>
  </v-main>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

const auth = useAuthStore()
const notices = useNotificationsStore()
const route = useRoute()
const router = useRouter()
const display = useDisplay()
const drawerOpen = ref(false)
const rail = ref(false)
const quickSearch = ref('')
const quickSearchMenu = ref(false)

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

const filteredNavigation = computed(() => {
  const q = quickSearch.value.trim().toLowerCase()
  if (!q) return visibleNavigation.value
  return visibleNavigation.value.filter((item) => item.title.toLowerCase().includes(q))
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
    <template #append>
      <div v-if="!isRail" class="sidebar-footer">
        <div class="clinic-online"><span></span> Clinic workspace <b>Online</b></div>
        <div class="sidebar-copyright">Clinic Care · Secure staff portal</div>
      </div>
    </template>
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
        <v-btn v-bind="props" icon="mdi-magnify" variant="text" aria-label="Quick navigation search" class="appbar-icon" />
      </template>
      <v-card min-width="290" max-width="340" rounded="lg" class="pa-3 notification-menu">
        <v-text-field
          v-model="quickSearch"
          prepend-inner-icon="mdi-magnify"
          placeholder="Jump to module..."
          density="compact"
          variant="outlined"
          hide-details
          autofocus
          class="mb-2"
        />
        <v-list density="compact" nav class="pa-0">
          <v-list-item
            v-for="item in filteredNavigation"
            :key="item.to"
            :prepend-icon="item.icon"
            :title="item.title"
            rounded="lg"
            @click="navigateFromSearch(item.to)"
          />
          <v-list-item v-if="!filteredNavigation.length" title="No matching modules" disabled />
        </v-list>
      </v-card>
    </v-menu>
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

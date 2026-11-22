<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

const auth = useAuthStore()
const notices = useNotificationsStore()
const route = useRoute()
const router = useRouter()
const drawerOpen = ref(false)
const rail = ref(false)

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
const visibleNavigation = computed(() => navigation.filter((item) => (!item.permission || auth.hasPermission(item.permission)) && (!item.role || auth.user?.role?.slug === item.role)))
const pageTitle = computed(() => route.meta.title as string ?? 'Dashboard')

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
    v-model="drawerOpen"
    :rail="rail"
    :temporary="$vuetify.display.smAndDown"
    :permanent="!$vuetify.display.smAndDown"
    width="264"
    rail-width="76"
    class="clinic-sidebar"
  >
    <div class="sidebar-brand">
      <RouterLink to="/" class="brand-lockup">
        <span class="brand-mark">C</span>
        <span v-if="!rail" class="brand-name">CLINIC <b>CARE</b><small>MANAGEMENT SYSTEM</small></span>
      </RouterLink>
      <v-btn v-if="!$vuetify.display.smAndDown" :icon="rail ? 'mdi-chevron-double-right' : 'mdi-chevron-double-left'" variant="text" size="small" class="rail-toggle" aria-label="Toggle sidebar" @click="rail = !rail" />
    </div>
    <div v-if="!rail" class="sidebar-label">WORKSPACE</div>
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
      <div v-if="!rail" class="sidebar-footer">
        <div class="clinic-online"><span></span> Clinic workspace <b>Online</b></div>
        <div class="sidebar-copyright">Clinic Care · Secure staff portal</div>
      </div>
    </template>
  </v-navigation-drawer>

  <v-app-bar flat class="clinic-appbar">
    <v-btn v-if="$vuetify.display.smAndDown" icon="mdi-menu" variant="text" class="mobile-menu" aria-label="Open navigation" @click="drawerOpen = true" />
    <div class="appbar-heading">
      <span class="appbar-eyebrow">CLINIC WORKSPACE</span>
      <span class="appbar-title">{{ pageTitle }}</span>
    </div>
    <v-spacer />
    <v-btn icon="mdi-magnify" variant="text" aria-label="Search" class="appbar-icon" />
    <v-menu location="bottom end" :close-on-content-click="false">
      <template #activator="{ props }">
        <v-btn v-bind="props" icon variant="text" aria-label="Notifications" class="appbar-icon notification-trigger">
          <v-badge dot color="error" offset-x="4" offset-y="4"><v-icon icon="mdi-bell-outline" /></v-badge>
        </v-btn>
      </template>
      <v-card min-width="280" rounded="lg" class="notification-menu"><div class="d-flex justify-space-between align-center"><b>Notifications</b><span class="notification-count">Today</span></div><v-divider class="my-3" /><div class="notification-empty"><v-icon icon="mdi-bell-check-outline" size="25" color="primary" /><span>You're all caught up.</span></div></v-card>
    </v-menu>
    <v-divider vertical class="appbar-divider mx-3" />
    <v-menu location="bottom end">
      <template #activator="{ props }">
        <v-btn v-bind="props" variant="text" class="profile-trigger" rounded="lg">
          <v-avatar size="36" color="primary" class="profile-avatar">{{ auth.user?.name?.slice(0, 1).toUpperCase() }}</v-avatar>
          <span class="profile-copy"><b>{{ auth.user?.name }}</b><small>{{ auth.user?.role?.name ?? 'Clinic staff' }}</small></span>
          <v-icon icon="mdi-chevron-down" size="small" />
        </v-btn>
      </template>
      <v-list density="compact" min-width="190" class="profile-menu">
        <v-list-item to="/profile" prepend-icon="mdi-account-outline" title="My profile" />
        <v-list-item v-if="auth.user?.role?.slug === 'administrator' && auth.hasPermission('users.manage')" to="/admin" prepend-icon="mdi-account-cog-outline" title="User management" />
        <v-divider class="my-1" />
        <v-list-item prepend-icon="mdi-logout" title="Sign out" @click="logout" />
      </v-list>
    </v-menu>
  </v-app-bar>

  <v-main class="clinic-main"><div class="page-container"><slot /></div></v-main>
</template>

<template>
  <v-app>
    <v-progress-linear
      v-if="isLoading"
      indeterminate
      color="primary"
      height="3"
      style="position: fixed; top: 0; left: 0; right: 0; z-index: 9999;"
    />
    <div v-if="!isInitialized" class="d-flex flex-column align-center justify-center fill-height ga-3">
      <v-progress-circular indeterminate color="primary" size="44" width="4" />
      <p class="text-body-2 text-medium-emphasis">Loading workspace…</p>
    </div>
    <template v-else>
      <PatientPortalLayout v-if="route.meta.requiresAuth && route.meta.portal === 'patient'"><RouterView /></PatientPortalLayout>
      <DashboardLayout v-else-if="route.meta.requiresAuth"><RouterView /></DashboardLayout>
      <RouterView v-else />
    </template>
    <v-snackbar v-model="notifications.visible" :color="notifications.color" timeout="3500" location="top end">
      {{ notifications.message }}
      <template #actions>
        <v-btn variant="text" @click="notifications.visible = false">Close</v-btn>
      </template>
    </v-snackbar>
  </v-app>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useGlobalLoading } from '@/composables/useGlobalLoading'
import { useAuthStore } from '@/stores/auth'
import { usePatientAuthStore } from '@/stores/patientAuth'
import { useNotificationsStore } from '@/stores/notifications'
import { useRoute } from 'vue-router'
import DashboardLayout from '@/components/DashboardLayout.vue'
import PatientPortalLayout from '@/components/PatientPortalLayout.vue'

const { isLoading } = useGlobalLoading()
const auth = useAuthStore()
const patientAuth = usePatientAuthStore()
const notifications = useNotificationsStore()
const route = useRoute()

const isInitialized = computed(() =>
  route.meta.portal === 'patient' ? patientAuth.initialized : auth.initialized,
)
</script>

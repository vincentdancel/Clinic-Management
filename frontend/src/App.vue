<template>
  <v-app>
    <DashboardLayout v-if="route.meta.requiresAuth"><RouterView /></DashboardLayout>
    <RouterView v-else />
    <v-snackbar v-model="notifications.visible" :color="notifications.color" timeout="3500" location="top end">
      {{ notifications.message }}
      <template #actions>
        <v-btn variant="text" @click="notifications.visible = false">Close</v-btn>
      </template>
    </v-snackbar>
  </v-app>
</template>

<script setup lang="ts">
import { useNotificationsStore } from '@/stores/notifications'
import { useRoute } from 'vue-router'
import DashboardLayout from '@/components/DashboardLayout.vue'

const notifications = useNotificationsStore()
const route = useRoute()
</script>

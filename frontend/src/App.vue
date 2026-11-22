<template>
  <v-app>
    <v-main>
      <RouterView />
    </v-main>
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

const notifications = useNotificationsStore()
</script>

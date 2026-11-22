<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const title = computed(() => route.meta.title as string ?? 'Clinic module')
const description = computed(() => route.meta.description as string ?? 'Manage this area of your clinic workspace.')
</script>

<template>
  <section class="module-page">
    <div class="module-intro"><p class="eyebrow">CLINIC WORKSPACE</p><h1>{{ title }}</h1><p>{{ description }}</p></div>
    <v-card class="panel-card module-placeholder" rounded="xl" elevation="0"><span class="module-icon"><v-icon icon="mdi-view-grid-outline" size="26" /></span><h2>{{ title }} workspace</h2><p>This workspace is ready for its clinic management tools and database-backed records.</p></v-card>
  </section>
</template>

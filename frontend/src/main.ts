import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createVuetify } from 'vuetify'

import App from './App.vue'
import router from './router'
import { applyDocumentThemeClass, getSavedTheme } from './composables/useThemeMode'

const initialTheme = getSavedTheme()
applyDocumentThemeClass(initialTheme)

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(createVuetify({
  theme: {
    defaultTheme: initialTheme,
    themes: {
      clinicLight: {
        dark: false,
        colors: {
          primary: '#1565C0',
          secondary: '#0D9488',
          background: '#F4F7FA',
          surface: '#FFFFFF',
          'surface-variant': '#EEF3F8',
          'on-background': '#1E293B',
          'on-surface': '#1E293B',
          'on-surface-variant': '#475569',
          success: '#16A34A',
          warning: '#D97706',
          error: '#DC2626',
          info: '#0284C7',
        },
      },
      clinicDark: {
        dark: true,
        colors: {
          primary: '#3B82F6',
          secondary: '#14B8A6',
          background: '#0B1220',
          surface: '#111827',
          'surface-variant': '#1E293B',
          'on-background': '#F1F5F9',
          'on-surface': '#F1F5F9',
          'on-surface-variant': '#94A3B8',
          success: '#22C55E',
          warning: '#F59E0B',
          error: '#EF4444',
          info: '#38BDF8',
        },
      },
    },
  },
  defaults: {
    VBtn: {
      style: 'text-transform: none; letter-spacing: normal;',
    },
    VDialog: {
      scrollable: true,
    },
  },
}))

app.mount('#app')

import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createVuetify } from 'vuetify'

import App from './App.vue'
import router from './router'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(createVuetify({
  theme: {
    defaultTheme: 'clinicLight',
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

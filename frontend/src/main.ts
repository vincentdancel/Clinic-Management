import './assets/main.css'
import 'vuetify/styles'

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
        colors: { primary: '#167D78', secondary: '#84B9A8', background: '#F4F8F7', surface: '#FFFFFF', error: '#B84444' },
      },
    },
  },
}))

app.mount('#app')

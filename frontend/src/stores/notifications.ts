import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useNotificationsStore = defineStore('notifications', () => {
  const message = ref('')
  const color = ref<'success' | 'error' | 'info'>('info')
  const visible = ref(false)

  function show(text: string, tone: 'success' | 'error' | 'info' = 'info') {
    message.value = text
    color.value = tone
    visible.value = true
  }

  return { message, color, visible, show }
})

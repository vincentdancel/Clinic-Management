import { computed, ref } from 'vue'

const pendingRequests = ref(0)
const navigating = ref(false)

export function startRequestLoading(): void {
  pendingRequests.value += 1
}

export function stopRequestLoading(): void {
  pendingRequests.value = Math.max(0, pendingRequests.value - 1)
}

export function setNavigating(value: boolean): void {
  navigating.value = value
}

export function useGlobalLoading() {
  const isLoading = computed(() => pendingRequests.value > 0 || navigating.value)
  return { isLoading, pendingRequests, navigating }
}

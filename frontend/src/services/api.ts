import axios from 'axios'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

export async function prepareCsrfCookie(): Promise<void> {
  const apiRoot = api.defaults.baseURL?.replace(/\/api\/?$/, '') ?? ''
  await axios.get(`${apiRoot}/sanctum/csrf-cookie`, { withCredentials: true })
}

export function getApiErrorMessage(error: unknown, fallback = 'An unexpected error occurred.'): string {
  if (axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(error)) {
    const firstFieldError = error.response?.data?.errors
      ? Object.values(error.response.data.errors).flat()[0]
      : undefined
    return firstFieldError ?? error.response?.data?.message ?? fallback
  }
  if (error instanceof Error && error.message) {
    return error.message
  }
  return fallback
}

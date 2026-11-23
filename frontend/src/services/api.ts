import axios from 'axios'

const MUTATING_METHODS = new Set(['post', 'put', 'patch', 'delete'])

let csrfToken: string | null = null
let csrfPromise: Promise<void> | null = null

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

export function getApiRoot(): string {
  return api.defaults.baseURL?.replace(/\/api\/?$/, '') ?? ''
}

export function clearCsrfToken(): void {
  csrfToken = null
}

export async function prepareCsrfCookie(force = false): Promise<void> {
  if (!force && csrfToken) {
    return
  }

  if (csrfPromise) {
    await csrfPromise
    return
  }

  csrfPromise = (async () => {
    const apiRoot = getApiRoot()
    const apiBase = api.defaults.baseURL?.replace(/\/$/, '') ?? ''
    await axios.get(`${apiRoot}/sanctum/csrf-cookie`, { withCredentials: true })
    const { data } = await axios.get<{ csrf_token?: string | null }>(`${apiBase}/auth/csrf-token`, {
      withCredentials: true,
      headers: {
        Accept: 'application/json',
      },
    })
    csrfToken = data.csrf_token ?? null
  })().finally(() => {
    csrfPromise = null
  })

  await csrfPromise
}

api.interceptors.request.use(async (config) => {
  const method = config.method?.toLowerCase() ?? 'get'
  if (MUTATING_METHODS.has(method)) {
    if (!csrfToken) {
      await prepareCsrfCookie()
    }
    if (csrfToken) {
      config.headers.set('X-CSRF-TOKEN', csrfToken)
    }
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  async (error: unknown) => {
    if (axios.isAxiosError(error) && error.response?.status === 419 && error.config) {
      const retryConfig = error.config as typeof error.config & { _csrfRetried?: boolean }
      if (!retryConfig._csrfRetried) {
        retryConfig._csrfRetried = true
        await prepareCsrfCookie(true)
        if (csrfToken) {
          retryConfig.headers.set('X-CSRF-TOKEN', csrfToken)
        }
        return api.request(retryConfig)
      }
    }
    return Promise.reject(error)
  },
)

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

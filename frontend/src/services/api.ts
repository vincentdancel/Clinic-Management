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

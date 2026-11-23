import { computed } from 'vue'
import { useTheme } from 'vuetify'

export const THEME_STORAGE_KEY = 'clinic-theme'
export type ClinicThemeName = 'clinicLight' | 'clinicDark'

export function applyDocumentThemeClass(themeName: ClinicThemeName): void {
  if (typeof document !== 'undefined') {
    document.documentElement.classList.toggle('dark', themeName === 'clinicDark')
  }
}

export function getSavedTheme(): ClinicThemeName {
  if (typeof localStorage === 'undefined') return 'clinicLight'
  return localStorage.getItem(THEME_STORAGE_KEY) === 'clinicDark' ? 'clinicDark' : 'clinicLight'
}

export function useThemeMode() {
  const theme = useTheme()

  const isDark = computed({
    get: () => theme.global.name.value === 'clinicDark',
    set: (dark: boolean) => {
      const next: ClinicThemeName = dark ? 'clinicDark' : 'clinicLight'
      theme.global.name.value = next
      if (typeof localStorage !== 'undefined') {
        localStorage.setItem(THEME_STORAGE_KEY, next)
      }
      applyDocumentThemeClass(next)
    },
  })

  function toggleTheme(): void {
    isDark.value = !isDark.value
  }

  return { isDark, toggleTheme }
}

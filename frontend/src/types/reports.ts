export type ReportPeriod = 'daily' | 'weekly' | 'monthly' | 'yearly'

export type ReportType =
  | 'patient-registrations'
  | 'appointments'
  | 'consultations'
  | 'doctor-activity'
  | 'employees'
  | 'daily-revenue'
  | 'monthly-revenue'
  | 'payments'
  | 'unpaid-invoices'
  | 'medicine-inventory'
  | 'low-stock'
  | 'expired-medicines'

export interface ReportCatalogItem {
  type: ReportType
  title: string
  description: string
  category: string
}

export interface ReportOptions {
  catalog: ReportCatalogItem[]
  can_export: boolean
  doctors: Array<{ id: number; name: string; employee_number: string }>
  departments: Array<{ id: number; name: string; code: string }>
  medicine_categories: Array<{ id: number; name: string }>
}

export interface ReportSummaryKpi {
  key: string
  label: string
  value: number | string
  format: 'number' | 'currency' | 'percent' | 'text'
  tone: 'blue' | 'teal' | 'green' | 'amber' | 'rose' | 'violet'
}

export interface ReportTrendChart {
  title: string
  type: 'bar' | 'line'
  labels: string[]
  series: Array<{ name: string; data: number[] }>
}

export interface ReportBreakdownChart {
  title: string
  type: 'donut' | 'bar'
  items: Array<{ label: string; value: number }>
}

export interface ReportColumn {
  key: string
  label: string
  align: 'left' | 'right'
  format: 'text' | 'date' | 'datetime' | 'currency' | 'number' | 'percent' | 'status'
}

export interface ReportPayload {
  type: ReportType
  title: string
  description: string
  category: string
  filters: {
    date_from: string | null
    date_to: string | null
    period: ReportPeriod
    search: string
    status: string | null
    doctor_id: number | null
    department_id: number | null
    method: string | null
    category_id: number | null
    gender: string | null
    expiry_scope: string | null
  }
  summary: ReportSummaryKpi[]
  charts: {
    trend: ReportTrendChart
    breakdown: ReportBreakdownChart
  }
  columns: ReportColumn[]
  rows: Array<Record<string, unknown>>
  generated_at: string
}

export function formatReportValue(value: unknown, format: string): string {
  if (value === null || value === undefined || value === '') return '—'
  if (format === 'currency') {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value))
  }
  if (format === 'percent') {
    return `${Number(value).toFixed(1)}%`
  }
  if (format === 'number') {
    return new Intl.NumberFormat('en-PH').format(Number(value))
  }
  if (format === 'datetime' && typeof value === 'string' && value !== '—') {
    return new Intl.DateTimeFormat('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(value))
  }
  if (format === 'date' && typeof value === 'string' && value !== '—') {
    return new Intl.DateTimeFormat('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(new Date(value))
  }
  if (format === 'status') {
    return String(value).replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase())
  }
  return String(value)
}


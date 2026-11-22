export type InvoiceStatus = 'unpaid' | 'partially_paid' | 'paid' | 'cancelled'
export type PaymentMethod = 'cash' | 'card' | 'bank_transfer' | 'other'
export type InvoiceItemType = 'consultation' | 'service' | 'other'

export interface BillingPatientOption {
  id: number
  patient_number: string
  full_name: string
  phone: string | null
}

export interface BillingServiceOption {
  id: number
  code: string
  name: string
  description: string | null
  price: number
}

export interface BillingConsultationOption {
  id: number
  consultation_number: string | null
  patient_id: number
  appointment_id: number | null
  started_at: string
  chief_complaint: string | null
  patient_name: string | null
  doctor_name: string | null
}

export interface BillingAppointmentOption {
  id: number
  appointment_number: string | null
  patient_id: number
  scheduled_at: string
  patient_name: string | null
  doctor_name: string | null
}

export interface InvoiceItemRecord {
  id?: number
  invoice_id?: number
  service_id: number | null
  prescription_item_id?: number | null
  item_type: InvoiceItemType
  description: string
  quantity: number
  unit_price: number
  line_total: number
  service?: {
    id: number
    code: string
    name: string
    price: number
  } | null
}

export interface PaymentRecord {
  id: number
  invoice_id: number
  receipt_number: string | null
  idempotency_key: string | null
  amount: number
  method: PaymentMethod
  reference_number: string | null
  paid_at: string
  status: 'completed' | 'voided' | 'refunded'
  notes: string | null
  created_at: string | null
  receiver?: { id: number; name: string } | null
  invoice?: {
    id: number
    invoice_number: string
    total_amount: number
    paid_amount: number
    remaining_balance: number
    status: InvoiceStatus
    issued_at: string | null
    patient?: {
      id: number
      patient_number: string
      first_name: string
      last_name: string
      full_name: string
      phone: string | null
      email: string | null
    } | null
    items?: InvoiceItemRecord[]
  } | null
}

export interface InvoiceRecord {
  id: number
  invoice_number: string
  patient_id: number
  appointment_id: number | null
  consultation_id: number | null
  issued_at: string
  due_at: string | null
  subtotal: number
  discount_amount: number
  tax_amount: number
  total_amount: number
  paid_amount: number
  remaining_balance: number
  status: InvoiceStatus
  notes: string | null
  created_at: string | null
  updated_at: string | null
  patient?: {
    id: number
    patient_number: string
    first_name: string
    last_name: string
    full_name: string
    phone: string | null
    email: string | null
    address: string | null
  } | null
  appointment?: {
    id: number
    appointment_number: string | null
    scheduled_at: string | null
    status: string
  } | null
  consultation?: {
    id: number
    consultation_number: string | null
    started_at: string | null
    chief_complaint: string | null
    doctor?: {
      id: number
      full_name: string
      job_title: string | null
    } | null
  } | null
  issuer?: { id: number; name: string } | null
  items?: InvoiceItemRecord[]
  payments?: PaymentRecord[]
}

export interface BillingSummary {
  total_billed: number
  total_collected: number
  total_outstanding: number
  unpaid_count: number
  partially_paid_count: number
  paid_count: number
}

export const INVOICE_STATUS_META: Record<InvoiceStatus, { label: string; color: string }> = {
  unpaid: { label: 'Unpaid', color: 'warning' },
  partially_paid: { label: 'Partially Paid', color: 'info' },
  paid: { label: 'Paid', color: 'success' },
  cancelled: { label: 'Cancelled', color: 'error' },
}

export const PAYMENT_METHOD_META: Record<PaymentMethod, { label: string; icon: string; color: string }> = {
  cash: { label: 'Cash', icon: 'mdi-cash', color: 'success' },
  card: { label: 'Card', icon: 'mdi-credit-card-outline', color: 'primary' },
  bank_transfer: { label: 'Bank Transfer', icon: 'mdi-bank-transfer', color: 'info' },
  other: { label: 'Other', icon: 'mdi-wallet-outline', color: 'secondary' },
}

export function formatCurrency(value: number | string | null | undefined): string {
  const num = Number(value ?? 0)
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number.isFinite(num) ? num : 0)
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}


export type ConsultationStatus = 'in_progress' | 'completed' | 'cancelled'

export interface ConsultationDoctorOption {
  id: number
  employee_number: string
  full_name: string
  job_title: string
  department: string | null
}

export interface ConsultationPatientOption {
  id: number
  patient_number: string
  full_name: string
  gender: string | null
  age: number | null
  phone: string | null
}

export interface ConsultationAppointmentOption {
  id: number
  appointment_number: string | null
  patient_id: number
  doctor_id: number
  scheduled_at: string
  reason: string | null
  patient_name: string | null
  doctor_name: string | null
}

export interface MedicineOption {
  id: number
  name: string
  generic_name: string | null
  dosage_form: string | null
  unit: string
}

export interface VitalSignRecord {
  id: number
  consultation_id: number
  measured_at: string
  systolic_bp: number | null
  diastolic_bp: number | null
  blood_pressure: string | null
  heart_rate_bpm: number | null
  respiratory_rate: number | null
  temperature_c: number | null
  weight_kg: number | null
  height_cm: number | null
  bmi: number | null
  bmi_category: string | null
  oxygen_saturation: number | null
  notes: string | null
  recorded_by: { id: number; name: string } | null
  created_at: string | null
}

export interface PrescriptionItemRecord {
  id: number
  prescription_id: number
  medicine_id: number | null
  medicine_name: string
  dosage: string
  frequency: string
  duration: string | null
  duration_days: number | null
  quantity: number
  quantity_unit: string
  instructions: string | null
}

export interface PrescriptionRecord {
  id: number
  consultation_id: number
  prescribed_at: string
  notes: string | null
  status: 'active' | 'dispensed' | 'cancelled'
  prescriber: {
    id: number
    employee_number: string
    full_name: string
    job_title: string
  } | null
  items: PrescriptionItemRecord[]
  created_at: string | null
}

export interface ConsultationRecord {
  id: number
  consultation_number: string
  patient_id: number
  doctor_id: number
  appointment_id: number | null
  started_at: string | null
  ended_at: string | null
  chief_complaint: string | null
  symptoms: string | null
  diagnosis: string | null
  treatment_plan: string | null
  clinical_notes: string | null
  follow_up_date: string | null
  status: ConsultationStatus
  created_at: string | null
  updated_at: string | null
  patient?: {
    id: number
    patient_number: string
    first_name: string
    last_name: string
    full_name: string
    gender: string | null
    date_of_birth: string | null
    age: number | null
    phone: string | null
    email: string | null
    address: string | null
    emergency_contact_name: string | null
    emergency_contact_phone: string | null
    status: 'active' | 'archived'
    allergies?: Array<{
      id: number
      allergen: string
      reaction: string | null
      severity: string | null
      notes: string | null
    }>
    medical_histories?: Array<{
      id: number
      condition_name: string
      details: string | null
      diagnosed_at: string | null
      resolved_at: string | null
    }>
  } | null
  doctor?: {
    id: number
    employee_number: string
    first_name: string
    last_name: string
    full_name: string
    job_title: string
    department: { id: number; name: string } | null
    specializations: Array<{ id: number; name: string; is_primary: boolean }>
  } | null
  appointment?: {
    id: number
    appointment_number: string | null
    scheduled_at: string
    status: string
    reason: string | null
  } | null
  recorder?: { id: number; name: string } | null
  vital_signs?: VitalSignRecord[]
  latest_vital_sign?: VitalSignRecord | null
  prescriptions?: PrescriptionRecord[]
}

export interface ConsultationAuditLog {
  id: number
  event: string
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  user: { id: number; name: string } | null
  created_at: string
}

export const CONSULTATION_STATUS_META: Record<ConsultationStatus, { label: string; color: string }> = {
  in_progress: { label: 'In Progress', color: 'primary' },
  completed: { label: 'Completed', color: 'success' },
  cancelled: { label: 'Cancelled', color: 'error' },
}

export function calculateBmi(weightKg: number | string | null, heightCm: number | string | null): { bmi: number; category: string; color: string } | null {
  const w = Number(weightKg)
  const h = Number(heightCm)
  if (!Number.isFinite(w) || !Number.isFinite(h) || w <= 0 || h <= 0) {
    return null
  }
  const heightMeters = h / 100
  const bmi = Math.round((w / (heightMeters * heightMeters)) * 10) / 10
  if (bmi < 18.5) return { bmi, category: 'Underweight', color: 'info' }
  if (bmi < 25) return { bmi, category: 'Normal', color: 'success' }
  if (bmi < 30) return { bmi, category: 'Overweight', color: 'warning' }
  return { bmi, category: 'Obese', color: 'error' }
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value))
}

export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const iso = value.includes('T') ? value : `${value}T00:00:00`
  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(iso))
}


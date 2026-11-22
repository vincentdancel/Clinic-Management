export type AppointmentStatus = 'pending' | 'confirmed' | 'completed' | 'cancelled' | 'no_show'

export interface AppointmentPatient {
  id: number
  patient_number: string
  first_name: string
  last_name: string
  full_name: string
  phone: string | null
  email: string | null
}

export interface AppointmentDoctor {
  id: number
  employee_number: string
  first_name: string
  last_name: string
  full_name: string
  job_title: string
  department: { id: number; name: string } | null
  specializations: { id: number; name: string; is_primary: boolean }[]
}

export interface AppointmentHistoryRecord {
  id: number
  action: string
  from_status: string | null
  to_status: string | null
  from_scheduled_at: string | null
  to_scheduled_at: string | null
  notes: string | null
  created_at: string
  actor: { id: number; name: string } | null
}

export interface AppointmentRecord {
  id: number
  appointment_number: string | null
  patient_id: number
  doctor_id: number
  doctor_schedule_id: number | null
  scheduled_at: string
  appointment_date: string
  appointment_time: string
  ends_at: string
  duration_minutes: number
  status: AppointmentStatus
  reason: string | null
  cancellation_reason: string | null
  created_at: string
  updated_at: string
  patient: AppointmentPatient | null
  doctor: AppointmentDoctor | null
  creator: { id: number; name: string } | null
  histories?: AppointmentHistoryRecord[]
}

export interface DoctorScheduleRecord {
  id: number
  employee_id: number
  day_of_week: number
  day_name: string
  starts_at: string
  ends_at: string
  slot_minutes: number
  valid_from: string | null
  valid_until: string | null
  is_active: boolean
}

export interface DoctorOption {
  id: number
  employee_number: string
  full_name: string
  job_title: string
  department: string | null
  specializations: { id: number; name: string; is_primary: boolean }[]
  schedules: DoctorScheduleRecord[]
}

export interface PatientOption {
  id: number
  patient_number: string
  full_name: string
  phone: string | null
}

export interface TimeSlot {
  time: string
  label: string
  available: boolean
  reason: string | null
}

export const APPOINTMENT_STATUS_META: Record<
  AppointmentStatus,
  { label: string; color: string; bg: string; text: string; icon: string }
> = {
  pending: {
    label: 'Pending',
    color: 'warning',
    bg: '#fff7e6',
    text: '#b76e00',
    icon: 'mdi-clock-outline',
  },
  confirmed: {
    label: 'Confirmed',
    color: 'info',
    bg: '#edf6fc',
    text: '#1f74a3',
    icon: 'mdi-check-circle-outline',
  },
  completed: {
    label: 'Completed',
    color: 'success',
    bg: '#edf7f2',
    text: '#24845f',
    icon: 'mdi-check-all',
  },
  cancelled: {
    label: 'Cancelled',
    color: 'error',
    bg: '#fcf0f0',
    text: '#b74b4b',
    icon: 'mdi-close-circle-outline',
  },
  no_show: {
    label: 'No Show',
    color: 'secondary',
    bg: '#f4f1fa',
    text: '#6f59a8',
    icon: 'mdi-account-off-outline',
  },
}

export function formatAppointmentTime(isoString: string): string {
  return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' }).format(new Date(isoString))
}

export function formatAppointmentDate(isoString: string): string {
  return new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(isoString))
}


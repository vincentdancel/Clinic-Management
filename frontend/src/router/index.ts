import { createRouter, createWebHistory } from 'vue-router'
import { setNavigating } from '@/composables/useGlobalLoading'
import { useAuthStore } from '@/stores/auth'
import { usePatientAuthStore } from '@/stores/patientAuth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guestOnly: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/', name: 'home', component: () => import('@/views/HomeView.vue'), meta: { requiresAuth: true, title: 'Dashboard' } },
    { path: '/patients', name: 'patients', component: () => import('@/views/PatientManagementView.vue'), meta: { requiresAuth: true, title: 'Patient Management', permission: 'patients.view' } },
    { path: '/patients/:patientId', name: 'patient-detail', component: () => import('@/views/PatientDetailView.vue'), meta: { requiresAuth: true, title: 'Patient Profile', permission: 'patients.view' } },
    { path: '/appointments', name: 'appointments', component: () => import('@/views/AppointmentManagementView.vue'), meta: { requiresAuth: true, title: 'Appointment Management', permission: 'appointments.view' } },
    { path: '/employees', name: 'employees', component: () => import('@/views/EmployeeManagementView.vue'), meta: { requiresAuth: true, title: 'Employee Management', permission: 'employees.view' } },
    { path: '/consultations', name: 'consultations', component: () => import('@/views/ConsultationManagementView.vue'), meta: { requiresAuth: true, title: 'Consultation & Medical Records', permission: 'clinical.view' } },
    { path: '/consultations/:consultationId', name: 'consultation-detail', component: () => import('@/views/ConsultationDetailView.vue'), meta: { requiresAuth: true, title: 'Medical Record', permission: 'clinical.view' } },
    { path: '/billing', name: 'billing', component: () => import('@/views/BillingManagementView.vue'), meta: { requiresAuth: true, title: 'Billing & Payments', permission: 'billing.view' } },
    { path: '/billing/invoices/:invoiceId', name: 'invoice-detail', component: () => import('@/views/InvoiceDetailView.vue'), meta: { requiresAuth: true, title: 'Invoice Details', permission: 'billing.view' } },
    { path: '/inventory', name: 'inventory', component: () => import('@/views/InventoryManagementView.vue'), meta: { requiresAuth: true, title: 'Medicine & Inventory', permission: 'inventory.manage' } },
    { path: '/reports', name: 'reports', component: () => import('@/views/ReportsAnalyticsView.vue'), meta: { requiresAuth: true, title: 'Reports & Analytics', permission: 'reports.view' } },
    { path: '/settings', name: 'settings', component: () => import('@/views/SettingsView.vue'), meta: { requiresAuth: true, title: 'Settings', description: 'Clinic preferences and workspace configuration.' } },
    { path: '/profile', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { requiresAuth: true, title: 'My profile' } },
    { path: '/admin', name: 'admin', component: () => import('@/views/AdminView.vue'), meta: { requiresAuth: true, title: 'User Management', roles: ['administrator'], permission: 'users.manage' } },

    // Patient Portal
    { path: '/patient/login', name: 'patient-login', component: () => import('@/views/patient/PatientLoginView.vue'), meta: { portal: 'patient', guestOnly: true } },
    { path: '/patient/dashboard', name: 'patient-dashboard', component: () => import('@/views/patient/PatientDashboardView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'Patient Dashboard' } },
    { path: '/patient/profile', name: 'patient-profile', component: () => import('@/views/patient/PatientProfileView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'My Profile', permission: 'patient.profile.view' } },
    { path: '/patient/appointments', name: 'patient-appointments', component: () => import('@/views/patient/PatientAppointmentsView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'My Appointments', permission: 'patient.appointments.view' } },
    { path: '/patient/medical-records', name: 'patient-medical-records', component: () => import('@/views/patient/PatientMedicalRecordsView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'My Medical Records', permission: 'patient.medical_records.view' } },
    { path: '/patient/consultations', name: 'patient-consultations', component: () => import('@/views/patient/PatientConsultationsView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'My Consultations', permission: 'patient.consultations.view' } },
    { path: '/patient/prescriptions', name: 'patient-prescriptions', component: () => import('@/views/patient/PatientPrescriptionsView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'My Prescriptions', permission: 'patient.prescriptions.view' } },
    { path: '/patient/billing', name: 'patient-billing', component: () => import('@/views/patient/PatientBillingView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'My Billing', permission: 'patient.billing.view' } },
    { path: '/patient/settings', name: 'patient-settings', component: () => import('@/views/patient/PatientSettingsView.vue'), meta: { requiresAuth: true, portal: 'patient', title: 'Account Settings', permission: 'patient.account.manage' } },
  ],
})

router.beforeEach(async (to) => {
  setNavigating(true)

  if (to.meta.portal === 'patient') {
    const patientAuth = usePatientAuthStore()
    await patientAuth.initialize()

    if (to.meta.requiresAuth && !patientAuth.user) {
      return { name: 'patient-login', query: { redirect: to.fullPath } }
    }

    if (to.meta.guestOnly && patientAuth.user) {
      return { name: 'patient-dashboard' }
    }

    const permission = to.meta.permission as string | undefined
    if (permission && !patientAuth.hasPermission(permission)) {
      return { name: 'patient-dashboard' }
    }

    return
  }

  const auth = useAuthStore()
  await auth.initialize()

  if (auth.user?.role?.slug === 'patient') {
    return { name: 'patient-dashboard' }
  }

  if (to.meta.requiresAuth && !auth.user) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.user) return { name: 'home' }

  const allowedRoles = to.meta.roles as string[] | undefined
  if (allowedRoles?.length && !allowedRoles.includes(auth.user?.role?.slug ?? '')) return { name: 'home' }

  const permission = to.meta.permission as string | undefined
  if (permission && !auth.hasPermission(permission)) return { name: 'home' }
})

router.afterEach(() => {
  setNavigating(false)
})

router.onError(() => {
  setNavigating(false)
})

export default router

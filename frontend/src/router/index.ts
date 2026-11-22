import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guestOnly: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('@/views/ForgotPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/reset-password', name: 'reset-password', component: () => import('@/views/ResetPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/', name: 'home', component: () => import('@/views/HomeView.vue'), meta: { requiresAuth: true, title: 'Dashboard' } },
    { path: '/patients', name: 'patients', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Patient Management', description: 'Patient directory and registration workspace.' } },
    { path: '/appointments', name: 'appointments', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Appointment Management', description: 'Clinic schedule and appointment coordination.' } },
    { path: '/employees', name: 'employees', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Employee Management', description: 'Staff directory, roles, and clinic team workspace.' } },
    { path: '/consultations', name: 'consultations', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Consultation & Medical Records', description: 'Consultation notes and longitudinal patient records.' } },
    { path: '/billing', name: 'billing', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Billing & Payments', description: 'Invoices, collections, and outstanding balances.' } },
    { path: '/inventory', name: 'inventory', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Medicine & Inventory', description: 'Medicine catalog, stock levels, and replenishment.' } },
    { path: '/reports', name: 'reports', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Reports & Analytics', description: 'Operational and financial reporting for your clinic.' } },
    { path: '/settings', name: 'settings', component: () => import('@/views/ModuleView.vue'), meta: { requiresAuth: true, title: 'Settings', description: 'Clinic preferences and workspace configuration.' } },
    { path: '/profile', name: 'profile', component: () => import('@/views/ProfileView.vue'), meta: { requiresAuth: true, title: 'My profile' } },
    { path: '/admin', name: 'admin', component: () => import('@/views/AdminView.vue'), meta: { requiresAuth: true, title: 'User Management', roles: ['administrator'], permission: 'users.manage' } },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.initialize()

  if (to.meta.requiresAuth && !auth.user) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.user) return { name: 'home' }

  const allowedRoles = to.meta.roles as string[] | undefined
  if (allowedRoles?.length && !allowedRoles.includes(auth.user?.role?.slug ?? '')) return { name: 'home' }

  const permission = to.meta.permission as string | undefined
  if (permission && !auth.hasPermission(permission)) return { name: 'home' }
})

export default router

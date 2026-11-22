<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { api } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

export interface EmployeeRecord {
  id: number
  employee_number: string
  first_name: string
  last_name: string
  full_name: string
  gender: string | null
  date_of_birth: string | null
  phone: string | null
  email: string | null
  address: string | null
  employee_category: string
  job_title: string
  hire_date: string
  employment_status: 'active' | 'inactive' | 'on_leave' | 'terminated'
  department: { id: number; name: string; code: string } | null
  specializations: { id: number; name: string; is_primary: boolean }[]
  account: { id: number; email: string; is_active: boolean; role: { id: number; name: string; slug: string } | null } | null
  deleted_at?: string | null
}

interface SelectOption { id: number; name: string; code?: string; slug?: string }
const props = defineProps<{
  modelValue: boolean
  employee: EmployeeRecord | null
  departments: SelectOption[]
  specializations: SelectOption[]
  roles: SelectOption[]
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()
const notifications = useNotificationsStore()
const isEditing = computed(() => props.employee !== null)
const busy = reactive({ saving: false })
const form = reactive({
  department_id: null as number | null, first_name: '', last_name: '', gender: null as string | null,
  date_of_birth: '', phone: '', email: '', employee_category: 'other_personnel', job_title: '',
  hire_date: '', employment_status: 'active', address: '', specialization_ids: [] as number[],
  primary_specialization_id: null as number | null, create_account: false, account_email: '',
  password: '', password_confirmation: '', role_id: null as number | null,
})

function resetForm() {
  const employee = props.employee
  Object.assign(form, {
    department_id: employee?.department?.id ?? null,
    first_name: employee?.first_name ?? '', last_name: employee?.last_name ?? '',
    gender: employee?.gender ?? null, date_of_birth: employee?.date_of_birth ?? '',
    phone: employee?.phone ?? '', email: employee?.email ?? '',
    employee_category: employee?.employee_category ?? 'other_personnel', job_title: employee?.job_title ?? '',
    hire_date: employee?.hire_date ?? '', employment_status: employee?.employment_status ?? 'active',
    address: employee?.address ?? '', specialization_ids: employee?.specializations.map((item) => item.id) ?? [],
    primary_specialization_id: employee?.specializations.find((item) => item.is_primary)?.id ?? null,
    create_account: false, account_email: employee?.account?.email ?? '', password: '', password_confirmation: '',
    role_id: employee?.account?.role?.id ?? null,
  })
}

watch(() => [props.modelValue, props.employee], ([isOpen]) => { if (isOpen) resetForm() })

async function saveEmployee() {
  busy.saving = true
  const payload: Record<string, unknown> = {
    ...form,
    date_of_birth: form.date_of_birth || null,
    email: form.email || null,
    gender: form.gender || null,
    account_email: form.account_email || null,
    password: form.password || null,
  }
  if (!form.create_account) {
    delete payload.create_account
    if (!props.employee?.account) {
      delete payload.account_email
      delete payload.password
      delete payload.password_confirmation
      delete payload.role_id
    }
  }
  try {
    if (isEditing.value) await api.put(`/employees/${props.employee?.id}`, payload)
    else await api.post('/employees', payload)
    notifications.show(isEditing.value ? 'Employee profile updated.' : 'Employee added to the directory.', 'success')
    emit('update:modelValue', false)
    emit('saved')
  } catch (error: any) {
    const firstError = Object.values(error.response?.data?.errors ?? {}).flat()[0]
    notifications.show(typeof firstError === 'string' ? firstError : error.response?.data?.message ?? 'Could not save this employee.', 'error')
  } finally {
    busy.saving = false
  }
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="900" scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="xl">
      <div class="dialog-heading"><div><p class="eyebrow">STAFF DIRECTORY</p><h2>{{ isEditing ? 'Edit employee' : 'Add an employee' }}</h2><p>{{ isEditing ? `Employee ID · ${employee?.employee_number}` : 'Add a team member and their clinic role.' }}</p></div><v-btn icon="mdi-close" variant="text" aria-label="Close dialog" @click="emit('update:modelValue', false)" /></div>
      <v-divider />
      <v-card-text class="patient-form-content"><v-form @submit.prevent="saveEmployee">
        <h3>Employee details</h3>
        <v-row dense>
          <v-col cols="12" sm="6"><v-text-field v-model="form.first_name" label="First name" variant="outlined" density="comfortable" required /></v-col>
          <v-col cols="12" sm="6"><v-text-field v-model="form.last_name" label="Last name" variant="outlined" density="comfortable" required /></v-col>
          <v-col cols="12" sm="6"><v-select v-model="form.department_id" :items="departments" item-title="name" item-value="id" label="Department" variant="outlined" density="comfortable" required /></v-col>
          <v-col cols="12" sm="6"><v-text-field v-model="form.job_title" label="Job title" variant="outlined" density="comfortable" required /></v-col>
          <v-col cols="12" sm="6"><v-select v-model="form.employee_category" :items="[{ title: 'Doctor', value: 'doctor' }, { title: 'Nurse', value: 'nurse' }, { title: 'Receptionist', value: 'receptionist' }, { title: 'Cashier', value: 'cashier' }, { title: 'Inventory staff', value: 'inventory_staff' }, { title: 'Other personnel', value: 'other_personnel' }]" label="Employee category" variant="outlined" density="comfortable" /></v-col>
          <v-col cols="12" sm="6"><v-select v-model="form.employment_status" :items="[{ title: 'Active', value: 'active' }, { title: 'Inactive', value: 'inactive' }, { title: 'On leave', value: 'on_leave' }, { title: 'Terminated', value: 'terminated' }]" label="Employment status" variant="outlined" density="comfortable" /></v-col>
          <v-col cols="12" sm="6"><v-text-field v-model="form.hire_date" label="Hire date" type="date" variant="outlined" density="comfortable" required /></v-col>
          <v-col cols="12" sm="6"><v-text-field v-model="form.date_of_birth" label="Date of birth" type="date" variant="outlined" density="comfortable" /></v-col>
          <v-col cols="12" sm="6"><v-select v-model="form.gender" :items="[{ title: 'Female', value: 'female' }, { title: 'Male', value: 'male' }, { title: 'Other', value: 'other' }, { title: 'Prefer not to say', value: 'prefer_not_to_say' }]" label="Gender" variant="outlined" density="comfortable" clearable /></v-col>
          <v-col cols="12" sm="6"><v-text-field v-model="form.phone" label="Phone number" type="tel" variant="outlined" density="comfortable" /></v-col>
          <v-col cols="12" sm="6"><v-text-field v-model="form.email" label="Contact email" type="email" variant="outlined" density="comfortable" /></v-col>
          <v-col v-if="form.employee_category === 'doctor'" cols="12" sm="8"><v-select v-model="form.specialization_ids" :items="specializations" item-title="name" item-value="id" label="Specializations" variant="outlined" density="comfortable" multiple chips /></v-col>
          <v-col v-if="form.employee_category === 'doctor'" cols="12" sm="4"><v-select v-model="form.primary_specialization_id" :items="specializations.filter((item) => form.specialization_ids.includes(item.id))" item-title="name" item-value="id" label="Primary specialty" variant="outlined" density="comfortable" clearable /></v-col>
          <v-col cols="12"><v-textarea v-model="form.address" label="Address" rows="2" auto-grow variant="outlined" density="comfortable" /></v-col>
        </v-row>
        <h3 class="form-section-heading">Login account</h3>
        <template v-if="employee?.account">
          <p class="text-body-2 mb-3">Linked account: <b>{{ employee.account.email }}</b> · {{ employee.account.role?.name ?? 'No role' }}. Leave the password blank to keep it unchanged.</p>
          <v-row dense><v-col cols="12" sm="4"><v-text-field v-model="form.account_email" label="Login email" type="email" variant="outlined" density="comfortable" /></v-col><v-col cols="12" sm="4"><v-select v-model="form.role_id" :items="roles" item-title="name" item-value="id" label="Account role" variant="outlined" density="comfortable" clearable /></v-col><v-col cols="12" sm="4"><v-text-field v-model="form.password" label="New password (optional)" type="password" variant="outlined" density="comfortable" /></v-col><v-col v-if="form.password" cols="12" sm="6"><v-text-field v-model="form.password_confirmation" label="Confirm new password" type="password" variant="outlined" density="comfortable" /></v-col></v-row>
        </template>
        <template v-else>
          <v-checkbox v-model="form.create_account" label="Create a login account for this employee" hide-details density="comfortable" />
          <v-row v-if="form.create_account" dense><v-col cols="12" sm="4"><v-text-field v-model="form.account_email" label="Login email" type="email" variant="outlined" density="comfortable" required /></v-col><v-col cols="12" sm="4"><v-select v-model="form.role_id" :items="roles" item-title="name" item-value="id" label="Account role" variant="outlined" density="comfortable" required /></v-col><v-col cols="12" sm="4"><v-text-field v-model="form.password" label="Password" type="password" variant="outlined" density="comfortable" required /></v-col><v-col cols="12" sm="6"><v-text-field v-model="form.password_confirmation" label="Confirm password" type="password" variant="outlined" density="comfortable" required /></v-col></v-row>
        </template>
        <div class="dialog-actions"><v-btn variant="text" @click="emit('update:modelValue', false)">Cancel</v-btn><v-btn type="submit" color="primary" rounded="lg" prepend-icon="mdi-content-save-outline" :loading="busy.saving">{{ isEditing ? 'Save changes' : 'Add employee' }}</v-btn></div>
      </v-form></v-card-text>
    </v-card>
  </v-dialog>
</template>

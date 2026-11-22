<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import EmployeeFormDialog, { type EmployeeRecord } from '@/components/EmployeeFormDialog.vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

interface Page { data: EmployeeRecord[]; meta: { total: number } }
interface Option { id: number; name: string; code?: string; slug?: string }
const auth = useAuthStore()
const notifications = useNotificationsStore()
const canManage = computed(() => auth.hasPermission('employees.manage'))
const employees = ref<EmployeeRecord[]>([])
const departments = ref<Option[]>([])
const specializations = ref<Option[]>([])
const roles = ref<Option[]>([])
const total = ref(0)
const busy = ref(false)
const dialogOpen = ref(false)
const editingEmployee = ref<EmployeeRecord | null>(null)
const search = ref('')
const departmentId = ref<number | null>(null)
const status = ref('active')
const options = reactive({ page: 1, itemsPerPage: 15 })
const headers = [
  { title: 'EMPLOYEE', key: 'full_name', sortable: false },
  { title: 'EMPLOYEE ID', key: 'employee_number', sortable: false },
  { title: 'DEPARTMENT / ROLE', key: 'department', sortable: false },
  { title: 'CONTACT', key: 'phone', sortable: false },
  { title: 'STATUS', key: 'employment_status', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

async function loadOptions() {
  try {
    const response = await api.get<{ departments: Option[]; specializations: Option[]; roles: Option[] }>('/employees/options')
    departments.value = response.data.departments
    specializations.value = response.data.specializations
    roles.value = response.data.roles
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load employee form options.'), 'error')
  }
}

async function loadEmployees() {
  busy.value = true
  try {
    const response = await api.get<Page>('/employees', { params: { search: search.value || undefined, department_id: departmentId.value || undefined, status: status.value, page: options.page } })
    employees.value = response.data.data
    total.value = response.data.meta.total
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load employee records.'), 'error')
  } finally {
    busy.value = false
  }
}

watch([search, departmentId, status], () => { options.page = 1; void loadEmployees() })
watch(() => options.page, () => void loadEmployees())
onMounted(() => { void loadOptions(); void loadEmployees() })

function addEmployee() { editingEmployee.value = null; dialogOpen.value = true }
function editEmployee(employee: EmployeeRecord) { editingEmployee.value = employee; dialogOpen.value = true }
function statusLabel(value: string) { return ({ active: 'Active', inactive: 'Inactive', on_leave: 'On leave', terminated: 'Terminated' } as Record<string, string>)[value] ?? value }
function statusColor(value: string) { return value === 'active' ? 'success' : value === 'on_leave' ? 'warning' : 'default' }

async function archiveEmployee(employee: EmployeeRecord) {
  if (!window.confirm(`Archive ${employee.full_name}'s employee record? Their linked login will also be deactivated.`)) return
  try {
    await api.delete(`/employees/${employee.id}`)
    notifications.show('Employee record archived.', 'success')
    await loadEmployees()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not archive this employee.'), 'error')
  }
}
</script>

<template>
  <section class="patient-list-page">
    <div class="patient-page-heading"><div><p class="eyebrow">CLINIC TEAM</p><h1>Employee management</h1><p>Search staff profiles, review departments, and manage clinic team records.</p></div><v-btn v-if="canManage" color="primary" prepend-icon="mdi-account-plus-outline" rounded="lg" @click="addEmployee">Add employee</v-btn></div>
    <v-card class="patient-list-card" rounded="xl" elevation="0">
      <div class="patient-list-toolbar"><div><h2>Employee directory</h2><p>{{ total.toLocaleString() }} {{ total === 1 ? 'employee' : 'employees' }}</p></div><div class="patient-filters"><v-text-field v-model="search" prepend-inner-icon="mdi-magnify" label="Search name, ID, phone or email" hide-details density="comfortable" variant="outlined" clearable class="patient-search" /><v-select v-model="departmentId" :items="[{ title: 'All departments', value: null }, ...departments.map((department) => ({ title: department.name, value: department.id }))]" hide-details density="comfortable" variant="outlined" class="patient-filter-select" /><v-select v-model="status" :items="[{ title: 'Active', value: 'active' }, { title: 'Inactive', value: 'inactive' }, { title: 'On leave', value: 'on_leave' }, { title: 'Terminated', value: 'terminated' }, { title: 'All statuses', value: 'all' }]" hide-details density="comfortable" variant="outlined" class="patient-filter-select" /></div></div>
      <v-data-table-server v-model:page="options.page" :headers="headers" :items="employees" :items-length="total" :items-per-page="options.itemsPerPage" :loading="busy" item-value="id" class="patient-data-table" hover @update:page="options.page = $event">
        <template #item.full_name="{ item }"><div class="patient-name-link"><v-avatar size="34" color="primary" variant="tonal">{{ item.first_name[0] }}{{ item.last_name[0] }}</v-avatar><span><b>{{ item.full_name }}</b><small>{{ item.job_title }} · {{ item.employee_category.replaceAll('_', ' ') }}</small></span></div></template>
        <template #item.employee_number="{ item }"><span class="patient-number">{{ item.employee_number }}</span></template>
        <template #item.department="{ item }"><span class="patient-date-cell">{{ item.department?.name ?? '—' }}<small>{{ item.specializations.map((specialization) => specialization.name).join(', ') || 'No specialty listed' }}</small></span></template>
        <template #item.phone="{ item }"><span class="patient-contact">{{ item.phone || item.email || '—' }}</span></template>
        <template #item.employment_status="{ item }"><v-chip size="small" :color="statusColor(item.employment_status)" variant="tonal" class="patient-status-chip">{{ statusLabel(item.employment_status) }}<template v-if="item.deleted_at"> · Archived</template></v-chip></template>
        <template #item.actions="{ item }"><div class="patient-actions"><v-btn v-if="canManage && !item.deleted_at" icon="mdi-pencil-outline" variant="text" size="small" aria-label="Edit employee" @click="editEmployee(item)" /><v-btn v-if="canManage && !item.deleted_at" icon="mdi-archive-outline" variant="text" size="small" color="error" aria-label="Archive employee" @click="archiveEmployee(item)" /></div></template>
        <template #no-data><div class="patient-empty"><v-icon icon="mdi-account-search-outline" size="32" /><b>No employees found</b><span>Try changing the search or filters, or add an employee.</span><v-btn v-if="canManage" color="primary" variant="tonal" prepend-icon="mdi-account-plus-outline" @click="addEmployee">Add employee</v-btn></div></template>
      </v-data-table-server>
    </v-card>
    <EmployeeFormDialog v-model="dialogOpen" :employee="editingEmployee" :departments="departments" :specializations="specializations" :roles="roles" @saved="loadEmployees" />
  </section>
</template>

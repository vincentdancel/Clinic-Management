<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'

interface Role { id: number; name: string; slug: string; description: string | null; permissions: { id: number; name: string; slug: string }[] }
interface ManagedUser { id: number; name: string; email: string; is_active: boolean; role: Role | null }
interface Permission { id: number; name: string; slug: string; description: string | null }

const auth = useAuthStore()
const notifications = useNotificationsStore()
const users = ref<ManagedUser[]>([])
const roles = ref<Role[]>([])
const permissions = ref<Permission[]>([])
const tab = ref('users')
const userDialog = ref(false)
const roleDialog = ref(false)
const permissionDialog = ref(false)
const busy = ref(false)
const editingUserId = ref<number | null>(null)
const newUser = ref({ name: '', email: '', password: '', password_confirmation: '', role_id: null as number | null })
const newRole = ref({ name: '', slug: '', description: '', permission_ids: [] as number[] })
const newPermission = ref({ name: '', slug: '', description: '' })
const editingRoleId = ref<number | null>(null)
const editingPermissionId = ref<number | null>(null)

async function loadData() {
  busy.value = true
  try {
    const [userResponse, roleResponse, permissionResponse] = await Promise.all([
      api.get('/admin/users'), api.get('/admin/roles'), api.get('/admin/permissions'),
    ])
    users.value = userResponse.data.users.data
    roles.value = roleResponse.data.roles
    permissions.value = permissionResponse.data.permissions
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load access management data.'), 'error')
  } finally {
    busy.value = false
  }
}

async function createUser() {
  try {
    if (editingUserId.value) {
      await api.put(`/admin/users/${editingUserId.value}`, {
        name: newUser.value.name,
        email: newUser.value.email,
        role_id: newUser.value.role_id,
      })
    } else {
      await api.post('/admin/users', newUser.value)
    }
    userDialog.value = false
    editingUserId.value = null
    newUser.value = { name: '', email: '', password: '', password_confirmation: '', role_id: null }
    notifications.show('User account saved.', 'success')
    await loadData()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not create the account. Check all fields.'), 'error')
  }
}

function openNewUser() {
  editingUserId.value = null
  newUser.value = { name: '', email: '', password: '', password_confirmation: '', role_id: null }
  userDialog.value = true
}

function editUser(user: ManagedUser) {
  editingUserId.value = user.id
  newUser.value = {
    name: user.name,
    email: user.email,
    password: '',
    password_confirmation: '',
    role_id: user.role?.id ?? null,
  }
  userDialog.value = true
}

async function toggleUser(user: ManagedUser) {
  try {
    const { data } = await api.patch(`/admin/users/${user.id}/active`, { is_active: !user.is_active })
    user.is_active = data.user.is_active
    notifications.show(data.message, 'success')
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not change this account status.'), 'error')
  }
}

async function createRole() {
  try {
    if (editingRoleId.value) {
      await api.put(`/admin/roles/${editingRoleId.value}`, newRole.value)
    } else {
      await api.post('/admin/roles', newRole.value)
    }
    roleDialog.value = false
    editingRoleId.value = null
    newRole.value = { name: '', slug: '', description: '', permission_ids: [] }
    notifications.show('Role saved.', 'success')
    await loadData()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not create the role.'), 'error')
  }
}

function openNewRole() {
  editingRoleId.value = null
  newRole.value = { name: '', slug: '', description: '', permission_ids: [] }
  roleDialog.value = true
}

function editRole(role: Role) {
  editingRoleId.value = role.id
  newRole.value = {
    name: role.name,
    slug: role.slug,
    description: role.description ?? '',
    permission_ids: role.permissions.map((permission) => permission.id),
  }
  roleDialog.value = true
}

async function removeRole(role: Role) {
  try {
    await api.delete(`/admin/roles/${role.id}`)
    notifications.show('Role deleted.', 'success')
    await loadData()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not delete this role.'), 'error')
  }
}

async function createPermission() {
  try {
    if (editingPermissionId.value) {
      await api.put(`/admin/permissions/${editingPermissionId.value}`, newPermission.value)
    } else {
      await api.post('/admin/permissions', newPermission.value)
    }
    permissionDialog.value = false
    editingPermissionId.value = null
    newPermission.value = { name: '', slug: '', description: '' }
    notifications.show('Permission saved.', 'success')
    await loadData()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not create the permission.'), 'error')
  }
}

function openNewPermission() {
  editingPermissionId.value = null
  newPermission.value = { name: '', slug: '', description: '' }
  permissionDialog.value = true
}

function editPermission(permission: Permission) {
  editingPermissionId.value = permission.id
  newPermission.value = { name: permission.name, slug: permission.slug, description: permission.description ?? '' }
  permissionDialog.value = true
}

async function removePermission(permission: Permission) {
  try {
    await api.delete(`/admin/permissions/${permission.id}`)
    notifications.show('Permission deleted.', 'success')
    await loadData()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not delete this permission.'), 'error')
  }
}

onMounted(loadData)
</script>

<template>
  <div class="app-shell"><header class="topbar"><RouterLink to="/" class="auth-brand text-decoration-none"><span class="brand-mark">C</span><span>CLINIC <b>CARE</b></span></RouterLink>
    <div class="d-flex align-center ga-3"><span class="text-body-2 text-medium-emphasis">{{ auth.user?.name }}</span><v-btn to="/" variant="text" prepend-icon="mdi-arrow-left">Workspace</v-btn></div></header>
    <main class="workspace"><div class="eyebrow">ADMINISTRATION</div><h1 class="text-h3 font-weight-bold mt-2">Access management</h1><p class="text-medium-emphasis mt-2">Manage staff accounts, roles, and the permissions available to each role.</p>
      <v-card class="feature-card mt-7" rounded="xl" elevation="0"><v-tabs v-model="tab" color="primary" show-arrows><v-tab value="users">User accounts</v-tab><v-tab value="roles">Roles</v-tab><v-tab value="permissions">Permissions</v-tab></v-tabs><v-divider />
        <v-progress-linear v-if="busy" indeterminate color="primary" height="2" />
        <v-window v-model="tab"><v-window-item value="users"><div class="pa-5 d-flex justify-end"><v-btn color="primary" prepend-icon="mdi-account-plus-outline" rounded="lg" @click="openNewUser">Add user</v-btn></div>
          <v-table><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="text-right">Actions</th></tr></thead><tbody>
            <tr v-for="user in users" :key="user.id"><td class="font-weight-medium">{{ user.name }}</td><td>{{ user.email }}</td><td><v-chip size="small" variant="tonal">{{ user.role?.name ?? 'Unassigned' }}</v-chip></td>
              <td><v-chip size="small" :color="user.is_active ? 'success' : 'default'" variant="tonal">{{ user.is_active ? 'Active' : 'Inactive' }}</v-chip></td><td class="text-right"><v-btn size="small" icon="mdi-pencil-outline" variant="text" aria-label="Edit user" @click="editUser(user)" /><v-btn size="small" variant="text" :disabled="user.id === auth.user?.id" @click="toggleUser(user)">{{ user.is_active ? 'Deactivate' : 'Activate' }}</v-btn></td></tr>
          </tbody></v-table></v-window-item>
          <v-window-item value="roles"><div class="pa-5 d-flex justify-end"><v-btn color="primary" prepend-icon="mdi-shield-plus-outline" rounded="lg" @click="openNewRole">Create role</v-btn></div>
            <v-list lines="two"><v-list-item v-for="role in roles" :key="role.id" :title="role.name" :subtitle="`${role.slug} · ${role.permissions.length} permissions`"><template #append><v-btn icon="mdi-pencil-outline" variant="text" aria-label="Edit role" @click="editRole(role)" /><v-btn icon="mdi-delete-outline" variant="text" aria-label="Delete role" @click="removeRole(role)" /></template></v-list-item></v-list>
          </v-window-item>
          <v-window-item value="permissions"><div class="pa-5 d-flex justify-end"><v-btn color="primary" prepend-icon="mdi-key-plus" rounded="lg" @click="openNewPermission">Add permission</v-btn></div>
            <v-list lines="two"><v-list-item v-for="permission in permissions" :key="permission.id" :title="permission.name" :subtitle="permission.slug"><template #append><v-btn icon="mdi-pencil-outline" variant="text" aria-label="Edit permission" @click="editPermission(permission)" /><v-btn icon="mdi-delete-outline" variant="text" aria-label="Delete permission" @click="removePermission(permission)" /></template></v-list-item></v-list>
          </v-window-item></v-window>
      </v-card>
    </main>
    <v-dialog v-model="userDialog" max-width="560"><v-card class="pa-5" rounded="xl"><v-card-title class="text-h5">{{ editingUserId ? 'Edit staff account' : 'Create staff account' }}</v-card-title><v-card-text><v-form @submit.prevent="createUser">
      <v-text-field v-model="newUser.name" label="Full name" variant="outlined" required /><v-text-field v-model="newUser.email" label="Email" type="email" variant="outlined" required />
      <v-select v-model="newUser.role_id" :items="roles" item-title="name" item-value="id" label="Role" variant="outlined" required />
      <template v-if="!editingUserId"><v-text-field v-model="newUser.password" label="Temporary password" type="password" variant="outlined" minlength="8" required /><v-text-field v-model="newUser.password_confirmation" label="Confirm password" type="password" variant="outlined" required /></template>
      <div class="d-flex justify-end ga-2 mt-2"><v-btn variant="text" @click="userDialog = false">Cancel</v-btn><v-btn type="submit" color="primary" :loading="busy">Save account</v-btn></div>
    </v-form></v-card-text></v-card></v-dialog>
    <v-dialog v-model="roleDialog" max-width="560"><v-card class="pa-5" rounded="xl"><v-card-title class="text-h5">{{ editingRoleId ? 'Edit role' : 'Create role' }}</v-card-title><v-card-text><v-form @submit.prevent="createRole">
      <v-text-field v-model="newRole.name" label="Role name" variant="outlined" required /><v-text-field v-model="newRole.slug" label="Role key (for example: doctor)" variant="outlined" required />
      <v-textarea v-model="newRole.description" label="Description" variant="outlined" rows="2" /><v-select v-model="newRole.permission_ids" :items="permissions" item-title="name" item-value="id" label="Permissions" variant="outlined" multiple chips />
      <div class="d-flex justify-end ga-2 mt-2"><v-btn variant="text" @click="roleDialog = false">Cancel</v-btn><v-btn type="submit" color="primary">Save role</v-btn></div>
    </v-form></v-card-text></v-card></v-dialog>
    <v-dialog v-model="permissionDialog" max-width="560"><v-card class="pa-5" rounded="xl"><v-card-title class="text-h5">{{ editingPermissionId ? 'Edit permission' : 'Add permission' }}</v-card-title><v-card-text><v-form @submit.prevent="createPermission">
      <v-text-field v-model="newPermission.name" label="Permission name" variant="outlined" required /><v-text-field v-model="newPermission.slug" label="Permission key (for example: inventory.manage)" variant="outlined" required />
      <v-textarea v-model="newPermission.description" label="Description" variant="outlined" rows="2" />
      <div class="d-flex justify-end ga-2 mt-2"><v-btn variant="text" @click="permissionDialog = false">Cancel</v-btn><v-btn type="submit" color="primary">Save permission</v-btn></div>
    </v-form></v-card-text></v-card></v-dialog>
  </div>
</template>

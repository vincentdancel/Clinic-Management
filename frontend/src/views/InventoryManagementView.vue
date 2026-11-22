<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import type {
  InventoryOptions,
  InventoryTransaction,
  MedicineCategory,
  MedicineItem,
} from '@/types/inventory'

const notifications = useNotificationsStore()

const activeTab = ref<'catalog' | 'transactions'>('catalog')
const loading = ref(false)
const txLoading = ref(false)
const saving = ref(false)
const options = ref<InventoryOptions>({
  categories: [],
  dosage_forms: [],
  units: [],
  summary: {
    total_medicines: 0,
    active_medicines: 0,
    low_stock_count: 0,
    out_of_stock_count: 0,
    expiring_soon_count: 0,
    expired_batches_count: 0,
    total_inventory_value: 0,
  },
})

const medicines = ref<MedicineItem[]>([])
const totalMedicines = ref(0)
const page = ref(1)
const perPage = ref(15)
const search = ref('')
const categoryFilter = ref<number | null>(null)
const stockStatusFilter = ref<'all' | 'low_stock' | 'out_of_stock' | 'expiring_soon' | 'expired'>('all')
const statusFilter = ref<'active' | 'inactive' | 'archived' | 'all'>('active')

const transactions = ref<InventoryTransaction[]>([])
const totalTransactions = ref(0)
const txPage = ref(1)
const txSearch = ref('')
const txTypeFilter = ref('all')

const medicineDialog = ref(false)
const editingMedicine = ref<MedicineItem | null>(null)
const medicineForm = reactive({
  medicine_category_id: null as number | null,
  sku: '',
  name: '',
  generic_name: '',
  brand: '',
  dosage_form: 'Tablet',
  unit: 'tablets',
  reorder_level: 10,
  unit_cost: 0,
  selling_price: 0,
  manufacturer: '',
  requires_prescription: false,
  is_active: true,
})

const receiveDialog = ref(false)
const receiveTarget = ref<MedicineItem | null>(null)
const receiveForm = reactive({
  batch_number: '',
  received_at: new Date().toISOString().slice(0, 10),
  expires_at: '',
  quantity_received: 1,
  unit_cost: 0,
  notes: '',
})

const adjustDialog = ref(false)
const adjustTarget = ref<MedicineItem | null>(null)
const adjustForm = reactive({
  type: 'adjustment_out' as 'adjustment_in' | 'adjustment_out' | 'expired' | 'damaged' | 'return',
  quantity: 1,
  reference_number: '',
  notes: '',
})

const detailDialog = ref(false)
const detailLoading = ref(false)
const detailMedicine = ref<MedicineItem | null>(null)

const categoryDialog = ref(false)
const categoriesList = ref<MedicineCategory[]>([])
const categoryForm = reactive({ id: null as number | null, name: '', description: '' })

const phpCurrency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' })

const summaryCards = computed(() => [
  { label: 'Active Medicines', value: options.value.summary.active_medicines, icon: 'mdi-pill', color: 'primary' },
  { label: 'Low Stock Alerts', value: options.value.summary.low_stock_count, icon: 'mdi-alert-outline', color: 'warning' },
  { label: 'Out of Stock', value: options.value.summary.out_of_stock_count, icon: 'mdi-package-variant-remove', color: 'error' },
  { label: 'Expiring / Expired', value: options.value.summary.expiring_soon_count + options.value.summary.expired_batches_count, icon: 'mdi-calendar-alert', color: 'deep-orange' },
  { label: 'Inventory Value', value: phpCurrency.format(options.value.summary.total_inventory_value), icon: 'mdi-cash-multiple', color: 'teal' },
])

const medicineHeaders = [
  { title: 'SKU / Medicine', key: 'name', sortable: false },
  { title: 'Category', key: 'category_name', sortable: false },
  { title: 'Stock', key: 'stock_quantity', sortable: false },
  { title: 'Reorder Level', key: 'reorder_level', sortable: false },
  { title: 'Pricing', key: 'selling_price', sortable: false },
  { title: 'Earliest Expiry', key: 'earliest_expiry', sortable: false },
  { title: 'Status', key: 'status', sortable: false },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end' as const },
]

const transactionHeaders = [
  { title: 'Date / Time', key: 'occurred_at', sortable: false },
  { title: 'Medicine', key: 'medicine_name', sortable: false },
  { title: 'Type', key: 'type', sortable: false },
  { title: 'Qty Change', key: 'quantity_change', sortable: false },
  { title: 'Batch / Ref', key: 'reference_number', sortable: false },
  { title: 'Performed By', key: 'performer_name', sortable: false },
  { title: 'Notes', key: 'notes', sortable: false },
]

async function loadOptions() {
  try {
    const res = await api.get<InventoryOptions>('/inventory/options')
    options.value = res.data
    categoriesList.value = res.data.categories
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to load inventory options.'), 'error')
  }
}

async function loadMedicines() {
  loading.value = true
  try {
    const res = await api.get<{ data: MedicineItem[]; meta: { total: number } }>('/inventory/medicines', {
      params: {
        page: page.value,
        per_page: perPage.value,
        search: search.value || undefined,
        category_id: categoryFilter.value || undefined,
        stock_status: stockStatusFilter.value,
        status: statusFilter.value,
      },
    })
    medicines.value = res.data.data
    totalMedicines.value = res.data.meta.total
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to load medicines.'), 'error')
  } finally {
    loading.value = false
  }
}

async function loadTransactions() {
  txLoading.value = true
  try {
    const res = await api.get<{ data: InventoryTransaction[]; meta: { total: number } }>('/inventory/transactions', {
      params: {
        page: txPage.value,
        per_page: 20,
        search: txSearch.value || undefined,
        type: txTypeFilter.value,
      },
    })
    transactions.value = res.data.data
    totalTransactions.value = res.data.meta.total
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to load inventory transactions.'), 'error')
  } finally {
    txLoading.value = false
  }
}

async function refreshAll() {
  await Promise.all([loadOptions(), loadMedicines()])
  if (activeTab.value === 'transactions') {
    await loadTransactions()
  }
}

function openCreateMedicine() {
  editingMedicine.value = null
  Object.assign(medicineForm, {
    medicine_category_id: options.value.categories[0]?.id ?? null,
    sku: '',
    name: '',
    generic_name: '',
    brand: '',
    dosage_form: 'Tablet',
    unit: 'tablets',
    reorder_level: 10,
    unit_cost: 0,
    selling_price: 0,
    manufacturer: '',
    requires_prescription: false,
    is_active: true,
  })
  medicineDialog.value = true
}

function openEditMedicine(item: MedicineItem) {
  editingMedicine.value = item
  Object.assign(medicineForm, {
    medicine_category_id: item.medicine_category_id,
    sku: item.sku,
    name: item.name,
    generic_name: item.generic_name ?? '',
    brand: item.brand ?? '',
    dosage_form: item.dosage_form ?? 'Tablet',
    unit: item.unit,
    reorder_level: item.reorder_level,
    unit_cost: item.unit_cost,
    selling_price: item.selling_price,
    manufacturer: item.manufacturer ?? '',
    requires_prescription: item.requires_prescription,
    is_active: item.is_active,
  })
  medicineDialog.value = true
}

async function saveMedicine() {
  saving.value = true
  try {
    const payload = {
      ...medicineForm,
      sku: medicineForm.sku.trim() || undefined,
      generic_name: medicineForm.generic_name.trim() || null,
      brand: medicineForm.brand.trim() || null,
      manufacturer: medicineForm.manufacturer.trim() || null,
    }
    if (editingMedicine.value) {
      await api.put(`/inventory/medicines/${editingMedicine.value.id}`, payload)
      notifications.show('Medicine updated successfully.', 'success')
    } else {
      await api.post('/inventory/medicines', payload)
      notifications.show('Medicine created successfully.', 'success')
    }
    medicineDialog.value = false
    await refreshAll()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to save medicine.'), 'error')
  } finally {
    saving.value = false
  }
}

async function archiveMedicine(item: MedicineItem) {
  try {
    await api.delete(`/inventory/medicines/${item.id}`)
    notifications.show(`${item.name} archived.`, 'success')
    await refreshAll()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to archive medicine.'), 'error')
  }
}

function openReceiveStock(item: MedicineItem) {
  receiveTarget.value = item
  Object.assign(receiveForm, {
    batch_number: `B-${new Date().toISOString().slice(0, 10).replaceAll('-', '')}-${Math.floor(100 + Math.random() * 900)}`,
    received_at: new Date().toISOString().slice(0, 10),
    expires_at: '',
    quantity_received: 50,
    unit_cost: item.unit_cost,
    notes: '',
  })
  receiveDialog.value = true
}

async function submitReceiveStock() {
  if (!receiveTarget.value) return
  saving.value = true
  try {
    await api.post(`/inventory/medicines/${receiveTarget.value.id}/receive`, {
      ...receiveForm,
      expires_at: receiveForm.expires_at || null,
    })
    notifications.show('Stock batch received.', 'success')
    receiveDialog.value = false
    await refreshAll()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to receive stock batch.'), 'error')
  } finally {
    saving.value = false
  }
}

function openAdjustStock(item: MedicineItem) {
  adjustTarget.value = item
  Object.assign(adjustForm, {
    type: 'adjustment_out',
    quantity: 1,
    reference_number: '',
    notes: '',
  })
  adjustDialog.value = true
}

async function submitAdjustStock() {
  if (!adjustTarget.value) return
  saving.value = true
  try {
    await api.post(`/inventory/medicines/${adjustTarget.value.id}/adjust`, adjustForm)
    notifications.show('Inventory stock adjusted.', 'success')
    adjustDialog.value = false
    await refreshAll()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to adjust inventory stock.'), 'error')
  } finally {
    saving.value = false
  }
}

async function openMedicineDetail(item: MedicineItem) {
  detailDialog.value = true
  detailLoading.value = true
  detailMedicine.value = null
  try {
    const res = await api.get<{ data: MedicineItem }>(`/inventory/medicines/${item.id}`)
    detailMedicine.value = res.data.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to load medicine details.'), 'error')
    detailDialog.value = false
  } finally {
    detailLoading.value = false
  }
}

async function openCategoriesDialog() {
  categoryDialog.value = true
  try {
    const res = await api.get<{ data: MedicineCategory[] }>('/inventory/categories')
    categoriesList.value = res.data.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to load categories.'), 'error')
  }
}

async function saveCategory() {
  if (!categoryForm.name.trim()) return
  saving.value = true
  try {
    if (categoryForm.id) {
      await api.put(`/inventory/categories/${categoryForm.id}`, {
        name: categoryForm.name,
        description: categoryForm.description || null,
      })
      notifications.show('Category updated.', 'success')
    } else {
      await api.post('/inventory/categories', {
        name: categoryForm.name,
        description: categoryForm.description || null,
      })
      notifications.show('Category created.', 'success')
    }
    Object.assign(categoryForm, { id: null, name: '', description: '' })
    const res = await api.get<{ data: MedicineCategory[] }>('/inventory/categories')
    categoriesList.value = res.data.data
    await loadOptions()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to save category.'), 'error')
  } finally {
    saving.value = false
  }
}

async function deleteCategory(cat: MedicineCategory) {
  try {
    await api.delete(`/inventory/categories/${cat.id}`)
    notifications.show('Category deleted.', 'success')
    const res = await api.get<{ data: MedicineCategory[] }>('/inventory/categories')
    categoriesList.value = res.data.data
    await loadOptions()
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Failed to delete category.'), 'error')
  }
}

function stockBadgeColor(item: MedicineItem): string {
  if (item.is_out_of_stock) return 'error'
  if (item.is_low_stock) return 'warning'
  return 'success'
}

function stockBadgeLabel(item: MedicineItem): string {
  if (item.is_out_of_stock) return 'Out of Stock'
  if (item.is_low_stock) return 'Low Stock'
  return 'In Stock'
}

function txTypeColor(type: string): string {
  if (type === 'stock_in' || type === 'adjustment_in' || type === 'return') return 'success'
  if (type === 'dispense') return 'primary'
  return 'error'
}

watch([categoryFilter, stockStatusFilter, statusFilter], () => {
  page.value = 1
  loadMedicines()
})

watch(activeTab, (tab) => {
  if (tab === 'transactions') loadTransactions()
})

onMounted(refreshAll)
</script>

<template>
  <section class="inventory-page">
    <div class="d-flex flex-wrap justify-space-between align-center ga-3 mb-5">
      <div>
        <p class="text-overline text-primary font-weight-bold mb-1">PHARMACY & SUPPLIES</p>
        <h1 class="text-h5 font-weight-bold">Medicine & Inventory Management</h1>
        <p class="text-body-2 text-medium-emphasis">Track medicine catalog, batch expiration, stock replenishment, and dispensing logs.</p>
      </div>
      <div class="d-flex flex-wrap ga-2">
        <v-btn variant="outlined" prepend-icon="mdi-shape-outline" rounded="lg" @click="openCategoriesDialog">Categories</v-btn>
        <v-btn color="primary" prepend-icon="mdi-plus" rounded="lg" @click="openCreateMedicine">Add Medicine</v-btn>
      </div>
    </div>

    <v-row dense class="mb-5">
      <v-col v-for="card in summaryCards" :key="card.label" cols="12" sm="6" md="4" lg>
        <v-card rounded="xl" border elevation="0" class="pa-4">
          <div class="d-flex justify-space-between align-center mb-2">
            <span class="text-caption text-medium-emphasis font-weight-medium">{{ card.label }}</span>
            <v-icon :icon="card.icon" :color="card.color" size="20" />
          </div>
          <div class="text-h6 font-weight-bold">{{ card.value }}</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card rounded="xl" border elevation="0">
      <v-tabs v-model="activeTab" color="primary" class="px-4 border-b">
        <v-tab value="catalog" prepend-icon="mdi-pill">Medicine Catalog</v-tab>
        <v-tab value="transactions" prepend-icon="mdi-history">Stock Transactions</v-tab>
      </v-tabs>

      <v-window v-model="activeTab">
        <v-window-item value="catalog">
          <div class="pa-4">
            <v-row dense class="mb-4">
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="search"
                  label="Search name, generic, brand, SKU..."
                  prepend-inner-icon="mdi-magnify"
                  density="compact"
                  variant="outlined"
                  hide-details
                  clearable
                  @keyup.enter="page = 1; loadMedicines()"
                  @click:clear="search = ''; page = 1; loadMedicines()"
                />
              </v-col>
              <v-col cols="12" sm="4" md="3">
                <v-select
                  v-model="categoryFilter"
                  :items="options.categories"
                  item-title="name"
                  item-value="id"
                  label="Category"
                  density="compact"
                  variant="outlined"
                  hide-details
                  clearable
                />
              </v-col>
              <v-col cols="12" sm="4" md="3">
                <v-select
                  v-model="stockStatusFilter"
                  :items="[
                    { title: 'All Stock Levels', value: 'all' },
                    { title: 'Low Stock', value: 'low_stock' },
                    { title: 'Out of Stock', value: 'out_of_stock' },
                    { title: 'Expiring Soon (30d)', value: 'expiring_soon' },
                    { title: 'Has Expired Batches', value: 'expired' },
                  ]"
                  label="Stock Alert Filter"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </v-col>
              <v-col cols="12" sm="4" md="2">
                <v-select
                  v-model="statusFilter"
                  :items="[
                    { title: 'Active', value: 'active' },
                    { title: 'Inactive', value: 'inactive' },
                    { title: 'Archived', value: 'archived' },
                    { title: 'All', value: 'all' },
                  ]"
                  label="Record Status"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </v-col>
            </v-row>

            <v-data-table-server
              v-model:page="page"
              v-model:items-per-page="perPage"
              :headers="medicineHeaders"
              :items="medicines"
              :items-length="totalMedicines"
              :loading="loading"
              @update:options="loadMedicines"
            >
              <template #item.name="{ item }">
                <div class="py-2">
                  <div class="font-weight-semibold">{{ item.name }}</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ item.sku }} <span v-if="item.generic_name">· {{ item.generic_name }}</span>
                    <v-chip v-if="item.requires_prescription" size="x-small" color="info" variant="tonal" class="ml-1">Rx</v-chip>
                  </div>
                </div>
              </template>

              <template #item.category_name="{ item }">
                {{ item.category_name ?? '—' }}
              </template>

              <template #item.stock_quantity="{ item }">
                <span class="font-weight-bold">{{ item.stock_quantity }}</span>
                <span class="text-caption text-medium-emphasis ml-1">{{ item.unit }}</span>
              </template>

              <template #item.reorder_level="{ item }">
                {{ item.reorder_level }} {{ item.unit }}
              </template>

              <template #item.selling_price="{ item }">
                <div>{{ phpCurrency.format(item.selling_price) }}</div>
                <div class="text-caption text-medium-emphasis">Cost: {{ phpCurrency.format(item.unit_cost) }}</div>
              </template>

              <template #item.earliest_expiry="{ item }">
                {{ item.earliest_expiry ?? '—' }}
              </template>

              <template #item.status="{ item }">
                <v-chip size="small" :color="stockBadgeColor(item)" variant="tonal">
                  {{ stockBadgeLabel(item) }}
                </v-chip>
              </template>

              <template #item.actions="{ item }">
                <div class="d-flex justify-end ga-1">
                  <v-btn icon="mdi-eye-outline" size="small" variant="text" title="View Batches & History" @click="openMedicineDetail(item)" />
                  <v-btn icon="mdi-package-variant-plus" size="small" variant="text" color="success" title="Receive Stock Batch" @click="openReceiveStock(item)" />
                  <v-btn icon="mdi-tune-vertical" size="small" variant="text" color="warning" title="Adjust Stock" @click="openAdjustStock(item)" />
                  <v-btn icon="mdi-pencil-outline" size="small" variant="text" color="primary" title="Edit Medicine" @click="openEditMedicine(item)" />
                  <v-btn v-if="!item.deleted_at" icon="mdi-archive-outline" size="small" variant="text" color="error" title="Archive Medicine" @click="archiveMedicine(item)" />
                </div>
              </template>
            </v-data-table-server>
          </div>
        </v-window-item>

        <v-window-item value="transactions">
          <div class="pa-4">
            <v-row dense class="mb-4">
              <v-col cols="12" md="5">
                <v-text-field
                  v-model="txSearch"
                  label="Search medicine, SKU, reference..."
                  prepend-inner-icon="mdi-magnify"
                  density="compact"
                  variant="outlined"
                  hide-details
                  clearable
                  @keyup.enter="txPage = 1; loadTransactions()"
                />
              </v-col>
              <v-col cols="12" md="3">
                <v-select
                  v-model="txTypeFilter"
                  :items="[
                    { title: 'All Types', value: 'all' },
                    { title: 'Stock In', value: 'stock_in' },
                    { title: 'Dispense', value: 'dispense' },
                    { title: 'Adjustment In', value: 'adjustment_in' },
                    { title: 'Adjustment Out', value: 'adjustment_out' },
                    { title: 'Expired', value: 'expired' },
                    { title: 'Damaged', value: 'damaged' },
                    { title: 'Return', value: 'return' },
                  ]"
                  label="Transaction Type"
                  density="compact"
                  variant="outlined"
                  hide-details
                  @update:model-value="txPage = 1; loadTransactions()"
                />
              </v-col>
            </v-row>

            <v-data-table-server
              v-model:page="txPage"
              :headers="transactionHeaders"
              :items="transactions"
              :items-length="totalTransactions"
              :loading="txLoading"
              @update:options="loadTransactions"
            >
              <template #item.occurred_at="{ item }">
                {{ item.occurred_at ? new Date(item.occurred_at).toLocaleString() : '—' }}
              </template>
              <template #item.medicine_name="{ item }">
                <div class="font-weight-medium">{{ item.medicine_name }}</div>
                <div class="text-caption text-medium-emphasis">{{ item.medicine_sku }}</div>
              </template>
              <template #item.type="{ item }">
                <v-chip size="small" :color="txTypeColor(item.type)" variant="tonal">
                  {{ item.type.replaceAll('_', ' ') }}
                </v-chip>
              </template>
              <template #item.quantity_change="{ item }">
                <span :class="item.quantity_change >= 0 ? 'text-success font-weight-bold' : 'text-error font-weight-bold'">
                  {{ item.quantity_change >= 0 ? '+' : '' }}{{ item.quantity_change }} {{ item.unit }}
                </span>
              </template>
              <template #item.reference_number="{ item }">
                {{ item.batch_number || item.reference_number || '—' }}
              </template>
            </v-data-table-server>
          </div>
        </v-window-item>
      </v-window>
    </v-card>

    <!-- Add/Edit Medicine Dialog -->
    <v-dialog v-model="medicineDialog" max-width="680">
      <v-card rounded="xl">
        <v-card-title class="pa-5 border-b font-weight-bold">
          {{ editingMedicine ? 'Edit Medicine' : 'Add New Medicine' }}
        </v-card-title>
        <v-card-text class="pa-5">
          <v-row dense>
            <v-col cols="12" sm="6">
              <v-select
                v-model="medicineForm.medicine_category_id"
                :items="options.categories"
                item-title="name"
                item-value="id"
                label="Category *"
                variant="outlined"
                density="compact"
              />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="medicineForm.sku" label="SKU (auto-generated if blank)" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="medicineForm.name" label="Medicine Name *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="medicineForm.generic_name" label="Generic Name" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="medicineForm.brand" label="Brand" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="medicineForm.manufacturer" label="Manufacturer" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-combobox v-model="medicineForm.dosage_form" :items="options.dosage_forms" label="Dosage Form" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-combobox v-model="medicineForm.unit" :items="options.units" label="Unit *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field v-model.number="medicineForm.reorder_level" type="number" min="0" label="Reorder Level *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="medicineForm.unit_cost" type="number" min="0" step="0.01" label="Unit Cost (PHP) *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="medicineForm.selling_price" type="number" min="0" step="0.01" label="Selling Price (PHP) *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-switch v-model="medicineForm.requires_prescription" label="Requires Prescription (Rx)" color="primary" density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="6">
              <v-switch v-model="medicineForm.is_active" label="Active in Catalog" color="success" density="compact" hide-details />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions class="pa-4 border-t justify-end ga-2">
          <v-btn variant="text" @click="medicineDialog = false">Cancel</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveMedicine">Save Medicine</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Receive Stock Batch Dialog -->
    <v-dialog v-model="receiveDialog" max-width="520">
      <v-card rounded="xl">
        <v-card-title class="pa-5 border-b font-weight-bold">
          Receive Stock — {{ receiveTarget?.name }}
        </v-card-title>
        <v-card-text class="pa-5">
          <v-row dense>
            <v-col cols="12">
              <v-text-field v-model="receiveForm.batch_number" label="Batch Number *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="receiveForm.received_at" type="date" label="Received Date" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model="receiveForm.expires_at" type="date" label="Expiration Date" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="receiveForm.quantity_received" type="number" min="1" label="Quantity Received *" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12" sm="6">
              <v-text-field v-model.number="receiveForm.unit_cost" type="number" min="0" step="0.01" label="Unit Cost (PHP)" variant="outlined" density="compact" />
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="receiveForm.notes" label="Notes" rows="2" variant="outlined" density="compact" />
            </v-col>
          </v-row>
        </v-card-text>
        <v-card-actions class="pa-4 border-t justify-end ga-2">
          <v-btn variant="text" @click="receiveDialog = false">Cancel</v-btn>
          <v-btn color="success" :loading="saving" @click="submitReceiveStock">Receive Stock</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Adjust Stock Dialog -->
    <v-dialog v-model="adjustDialog" max-width="480">
      <v-card rounded="xl">
        <v-card-title class="pa-5 border-b font-weight-bold">
          Adjust Stock — {{ adjustTarget?.name }}
        </v-card-title>
        <v-card-text class="pa-5">
          <v-select
            v-model="adjustForm.type"
            :items="[
              { title: 'Adjustment Out (Deduct)', value: 'adjustment_out' },
              { title: 'Damaged / Spoiled', value: 'damaged' },
              { title: 'Expired Disposal', value: 'expired' },
              { title: 'Adjustment In (Add)', value: 'adjustment_in' },
              { title: 'Patient Return (Add)', value: 'return' },
            ]"
            label="Adjustment Type *"
            variant="outlined"
            density="compact"
            class="mb-3"
          />
          <v-text-field v-model.number="adjustForm.quantity" type="number" min="0.001" label="Quantity *" variant="outlined" density="compact" class="mb-3" />
          <v-text-field v-model="adjustForm.reference_number" label="Reference Number" variant="outlined" density="compact" class="mb-3" />
          <v-textarea v-model="adjustForm.notes" label="Reason / Notes" rows="2" variant="outlined" density="compact" />
        </v-card-text>
        <v-card-actions class="pa-4 border-t justify-end ga-2">
          <v-btn variant="text" @click="adjustDialog = false">Cancel</v-btn>
          <v-btn color="warning" :loading="saving" @click="submitAdjustStock">Apply Adjustment</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Medicine Detail / Batches Dialog -->
    <v-dialog v-model="detailDialog" max-width="760">
      <v-card rounded="xl">
        <v-card-title class="pa-5 border-b d-flex justify-space-between align-center">
          <span class="font-weight-bold">{{ detailMedicine?.name ?? 'Medicine Details' }}</span>
          <v-btn icon="mdi-close" variant="text" @click="detailDialog = false" />
        </v-card-title>
        <v-card-text class="pa-5">
          <v-progress-linear v-if="detailLoading" indeterminate color="primary" class="mb-4" />
          <template v-else-if="detailMedicine">
            <div class="d-flex flex-wrap ga-4 mb-4">
              <div><span class="text-caption text-medium-emphasis">SKU:</span> <strong>{{ detailMedicine.sku }}</strong></div>
              <div><span class="text-caption text-medium-emphasis">Category:</span> <strong>{{ detailMedicine.category_name }}</strong></div>
              <div><span class="text-caption text-medium-emphasis">Available Stock:</span> <strong>{{ detailMedicine.stock_quantity }} {{ detailMedicine.unit }}</strong></div>
            </div>
            <h3 class="text-subtitle-2 font-weight-bold mb-2">Batches (FEFO Order)</h3>
            <v-table density="compact" class="mb-4">
              <thead>
                <tr><th>Batch #</th><th>Received</th><th>Expires</th><th>Remaining</th><th>Status</th></tr>
              </thead>
              <tbody>
                <tr v-for="batch in detailMedicine.batches" :key="batch.id">
                  <td>{{ batch.batch_number }}</td>
                  <td>{{ batch.received_at }}</td>
                  <td>{{ batch.expires_at ?? 'No expiry' }}</td>
                  <td>{{ batch.quantity_remaining }} / {{ batch.quantity_received }}</td>
                  <td>
                    <v-chip size="x-small" :color="batch.is_expired ? 'error' : 'success'" variant="tonal">
                      {{ batch.is_expired ? 'Expired' : 'Valid' }}
                    </v-chip>
                  </td>
                </tr>
                <tr v-if="!detailMedicine.batches?.length"><td colspan="5" class="text-center text-medium-emphasis py-3">No batches recorded.</td></tr>
              </tbody>
            </v-table>
          </template>
        </v-card-text>
      </v-card>
    </v-dialog>

    <!-- Categories Dialog -->
    <v-dialog v-model="categoryDialog" max-width="560">
      <v-card rounded="xl">
        <v-card-title class="pa-5 border-b d-flex justify-space-between align-center">
          <span class="font-weight-bold">Medicine Categories</span>
          <v-btn icon="mdi-close" variant="text" @click="categoryDialog = false" />
        </v-card-title>
        <v-card-text class="pa-5">
          <v-row dense class="mb-3">
            <v-col cols="12" sm="5">
              <v-text-field v-model="categoryForm.name" label="Category Name *" variant="outlined" density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="5">
              <v-text-field v-model="categoryForm.description" label="Description" variant="outlined" density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="2">
              <v-btn color="primary" block :loading="saving" @click="saveCategory">{{ categoryForm.id ? 'Save' : 'Add' }}</v-btn>
            </v-col>
          </v-row>
          <v-list density="compact" border rounded="lg">
            <v-list-item v-for="cat in categoriesList" :key="cat.id" :title="cat.name" :subtitle="cat.description ?? ''">
              <template #append>
                <v-btn icon="mdi-pencil-outline" size="x-small" variant="text" @click="Object.assign(categoryForm, { id: cat.id, name: cat.name, description: cat.description ?? '' })" />
                <v-btn icon="mdi-delete-outline" size="x-small" variant="text" color="error" @click="deleteCategory(cat)" />
              </template>
            </v-list-item>
          </v-list>
        </v-card-text>
      </v-card>
    </v-dialog>
  </section>
</template>


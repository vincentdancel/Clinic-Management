<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import InvoiceFormDialog from '@/components/InvoiceFormDialog.vue'
import PaymentFormDialog from '@/components/PaymentFormDialog.vue'
import PrintableInvoiceDialog from '@/components/PrintableInvoiceDialog.vue'
import PrintableReceiptDialog from '@/components/PrintableReceiptDialog.vue'
import { api } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import {
  formatCurrency,
  formatDate,
  formatDateTime,
  INVOICE_STATUS_META,
  PAYMENT_METHOD_META,
  type BillingAppointmentOption,
  type BillingConsultationOption,
  type BillingPatientOption,
  type BillingServiceOption,
  type BillingSummary,
  type InvoiceRecord,
  type PaymentRecord,
} from '@/types/billing'

const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationsStore()

const canManage = computed(() => auth.hasPermission('billing.manage'))

const activeTab = ref<'invoices' | 'transactions'>('invoices')

const invoices = ref<InvoiceRecord[]>([])
const invoiceTotal = ref(0)
const invoiceBusy = ref(false)
const summary = ref<BillingSummary>({
  total_billed: 0,
  total_collected: 0,
  total_outstanding: 0,
  unpaid_count: 0,
  partially_paid_count: 0,
  paid_count: 0,
})

const transactions = ref<PaymentRecord[]>([])
const transactionTotal = ref(0)
const transactionBusy = ref(false)

const patients = ref<BillingPatientOption[]>([])
const services = ref<BillingServiceOption[]>([])
const consultations = ref<BillingConsultationOption[]>([])
const appointments = ref<BillingAppointmentOption[]>([])

const invoiceSearch = ref('')
const statusFilter = ref('all')
const invoiceOptions = reactive({ page: 1, itemsPerPage: 15 })

const transactionSearch = ref('')
const methodFilter = ref('all')
const transactionOptions = reactive({ page: 1, itemsPerPage: 15 })

const createDialogOpen = ref(false)
const paymentDialogOpen = ref(false)
const printInvoiceDialogOpen = ref(false)
const printReceiptDialogOpen = ref(false)

const activeInvoice = ref<InvoiceRecord | null>(null)
const activePayment = ref<PaymentRecord | null>(null)

const invoiceHeaders = [
  { title: 'INVOICE #', key: 'invoice_number', sortable: false },
  { title: 'ISSUED', key: 'issued_at', sortable: false },
  { title: 'PATIENT', key: 'patient', sortable: false },
  { title: 'TOTAL', key: 'total_amount', sortable: false, align: 'end' as const },
  { title: 'PAID', key: 'paid_amount', sortable: false, align: 'end' as const },
  { title: 'BALANCE', key: 'remaining_balance', sortable: false, align: 'end' as const },
  { title: 'STATUS', key: 'status', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

const transactionHeaders = [
  { title: 'RECEIPT #', key: 'receipt_number', sortable: false },
  { title: 'DATE & TIME', key: 'paid_at', sortable: false },
  { title: 'INVOICE #', key: 'invoice', sortable: false },
  { title: 'PATIENT', key: 'patient', sortable: false },
  { title: 'METHOD', key: 'method', sortable: false },
  { title: 'REFERENCE', key: 'reference_number', sortable: false },
  { title: 'AMOUNT', key: 'amount', sortable: false, align: 'end' as const },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

async function loadOptions() {
  try {
    const { data } = await api.get('/invoices/options')
    patients.value = data.patients
    services.value = data.services
    consultations.value = data.consultations
    appointments.value = data.appointments
  } catch {
    // Only needed when creating invoices
  }
}

async function loadInvoices() {
  invoiceBusy.value = true
  try {
    const { data } = await api.get('/invoices', {
      params: {
        search: invoiceSearch.value || undefined,
        status: statusFilter.value,
        page: invoiceOptions.page,
        per_page: invoiceOptions.itemsPerPage,
      },
    })
    invoices.value = data.data
    invoiceTotal.value = data.meta.total
    if (data.summary) summary.value = data.summary
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? 'Could not load invoices.', 'error')
  } finally {
    invoiceBusy.value = false
  }
}

async function loadTransactions() {
  transactionBusy.value = true
  try {
    const { data } = await api.get('/payments', {
      params: {
        search: transactionSearch.value || undefined,
        method: methodFilter.value,
        page: transactionOptions.page,
        per_page: transactionOptions.itemsPerPage,
      },
    })
    transactions.value = data.data
    transactionTotal.value = data.meta.total
  } catch (error: any) {
    notifications.show(error.response?.data?.message ?? 'Could not load transaction history.', 'error')
  } finally {
    transactionBusy.value = false
  }
}

function openPaymentDialog(invoice: InvoiceRecord) {
  activeInvoice.value = invoice
  paymentDialogOpen.value = true
}

function openPrintInvoice(invoice: InvoiceRecord) {
  activeInvoice.value = invoice
  printInvoiceDialogOpen.value = true
}

function openPrintReceipt(payment: PaymentRecord) {
  activePayment.value = payment
  printReceiptDialogOpen.value = true
}

function onInvoiceSaved() {
  loadInvoices()
  loadOptions()
}

function onPaymentRecorded(payload: { payment: PaymentRecord; invoice: InvoiceRecord }) {
  loadInvoices()
  loadTransactions()
  activePayment.value = payload.payment
  printReceiptDialogOpen.value = true
}

watch([invoiceSearch, statusFilter], () => {
  invoiceOptions.page = 1
  loadInvoices()
})

watch([transactionSearch, methodFilter], () => {
  transactionOptions.page = 1
  loadTransactions()
})

onMounted(() => {
  loadInvoices()
  loadTransactions()
  loadOptions()
})
</script>

<template>
  <div class="d-flex flex-column ga-6">
    <div class="d-flex flex-wrap align-center justify-space-between ga-4">
      <div>
        <h1 class="text-h5 font-weight-bold">Billing &amp; Payment Management</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Generate patient invoices, record full or partial payments, and print official receipts.
        </p>
      </div>
      <v-btn v-if="canManage" color="primary" prepend-icon="mdi-plus" @click="createDialogOpen = true">
        Create Invoice
      </v-btn>
    </div>

    <v-row dense>
      <v-col cols="12" sm="4">
        <v-card rounded="lg" variant="outlined" class="pa-4 bg-white">
          <div class="text-caption text-medium-emphasis">TOTAL BILLED</div>
          <div class="text-h6 font-weight-bold mt-1">{{ formatCurrency(summary.total_billed) }}</div>
          <div class="text-caption text-medium-emphasis mt-1">{{ summary.paid_count }} paid invoices</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="4">
        <v-card rounded="lg" variant="outlined" class="pa-4 bg-white">
          <div class="text-caption text-medium-emphasis">TOTAL COLLECTED</div>
          <div class="text-h6 font-weight-bold text-success mt-1">{{ formatCurrency(summary.total_collected) }}</div>
          <div class="text-caption text-medium-emphasis mt-1">Completed payments</div>
        </v-card>
      </v-col>
      <v-col cols="12" sm="4">
        <v-card rounded="lg" variant="outlined" class="pa-4 bg-white">
          <div class="text-caption text-medium-emphasis">OUTSTANDING BALANCE</div>
          <div class="text-h6 font-weight-bold text-warning mt-1">{{ formatCurrency(summary.total_outstanding) }}</div>
          <div class="text-caption text-medium-emphasis mt-1">
            {{ summary.unpaid_count }} unpaid · {{ summary.partially_paid_count }} partial
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-card rounded="lg" variant="outlined" class="bg-white">
      <v-tabs v-model="activeTab" color="primary" class="border-b px-4">
        <v-tab value="invoices" prepend-icon="mdi-file-document-multiple-outline">Invoices</v-tab>
        <v-tab value="transactions" prepend-icon="mdi-history">Transaction History</v-tab>
      </v-tabs>

      <v-window v-model="activeTab">
        <v-window-item value="invoices">
          <div class="pa-4 d-flex flex-wrap ga-3 align-center border-b">
            <v-text-field
              v-model="invoiceSearch"
              prepend-inner-icon="mdi-magnify"
              label="Search invoice # or patient"
              variant="outlined"
              density="compact"
              hide-details
              clearable
              style="min-width: 260px; max-width: 360px"
            />
            <v-select
              v-model="statusFilter"
              :items="[
                { title: 'All Statuses', value: 'all' },
                { title: 'Unpaid', value: 'unpaid' },
                { title: 'Partially Paid', value: 'partially_paid' },
                { title: 'Paid', value: 'paid' },
                { title: 'Cancelled', value: 'cancelled' },
              ]"
              label="Status"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 200px"
            />
          </div>

          <v-data-table-server
            v-model:page="invoiceOptions.page"
            v-model:items-per-page="invoiceOptions.itemsPerPage"
            :headers="invoiceHeaders"
            :items="invoices"
            :items-length="invoiceTotal"
            :loading="invoiceBusy"
            @update:options="loadInvoices"
          >
            <template #item.invoice_number="{ item }">
              <router-link :to="`/billing/invoices/${item.id}`" class="font-weight-bold text-primary text-decoration-none">
                {{ item.invoice_number }}
              </router-link>
            </template>

            <template #item.issued_at="{ item }">
              {{ formatDate(item.issued_at) }}
            </template>

            <template #item.patient="{ item }">
              <div class="font-weight-medium">{{ item.patient?.full_name ?? '—' }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.patient?.patient_number ?? '' }}</div>
            </template>

            <template #item.total_amount="{ item }">
              <span class="font-weight-medium">{{ formatCurrency(item.total_amount) }}</span>
            </template>

            <template #item.paid_amount="{ item }">
              <span class="text-success">{{ formatCurrency(item.paid_amount) }}</span>
            </template>

            <template #item.remaining_balance="{ item }">
              <span :class="item.remaining_balance > 0 ? 'font-weight-bold text-warning' : 'text-medium-emphasis'">
                {{ formatCurrency(item.remaining_balance) }}
              </span>
            </template>

            <template #item.status="{ item }">
              <v-chip size="small" :color="INVOICE_STATUS_META[item.status]?.color" variant="tonal">
                {{ INVOICE_STATUS_META[item.status]?.label ?? item.status }}
              </v-chip>
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex justify-end ga-1">
                <v-btn
                  v-if="canManage && (item.status === 'unpaid' || item.status === 'partially_paid')"
                  size="small"
                  color="primary"
                  variant="tonal"
                  @click="openPaymentDialog(item)"
                >
                  Pay
                </v-btn>
                <v-btn
                  icon="mdi-printer-outline"
                  size="small"
                  variant="text"
                  title="Print Invoice"
                  @click="openPrintInvoice(item)"
                />
                <v-btn
                  icon="mdi-eye-outline"
                  size="small"
                  variant="text"
                  title="Invoice Details"
                  @click="router.push(`/billing/invoices/${item.id}`)"
                />
              </div>
            </template>
          </v-data-table-server>
        </v-window-item>

        <v-window-item value="transactions">
          <div class="pa-4 d-flex flex-wrap ga-3 align-center border-b">
            <v-text-field
              v-model="transactionSearch"
              prepend-inner-icon="mdi-magnify"
              label="Search receipt #, invoice #, or patient"
              variant="outlined"
              density="compact"
              hide-details
              clearable
              style="min-width: 260px; max-width: 360px"
            />
            <v-select
              v-model="methodFilter"
              :items="[
                { title: 'All Methods', value: 'all' },
                { title: 'Cash', value: 'cash' },
                { title: 'Card', value: 'card' },
                { title: 'Bank Transfer', value: 'bank_transfer' },
                { title: 'Other', value: 'other' },
              ]"
              label="Payment Method"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 200px"
            />
          </div>

          <v-data-table-server
            v-model:page="transactionOptions.page"
            v-model:items-per-page="transactionOptions.itemsPerPage"
            :headers="transactionHeaders"
            :items="transactions"
            :items-length="transactionTotal"
            :loading="transactionBusy"
            @update:options="loadTransactions"
          >
            <template #item.receipt_number="{ item }">
              <span class="font-weight-bold">{{ item.receipt_number }}</span>
            </template>

            <template #item.paid_at="{ item }">
              {{ formatDateTime(item.paid_at) }}
            </template>

            <template #item.invoice="{ item }">
              <router-link
                v-if="item.invoice"
                :to="`/billing/invoices/${item.invoice.id}`"
                class="text-primary text-decoration-none font-weight-medium"
              >
                {{ item.invoice.invoice_number }}
              </router-link>
              <span v-else>—</span>
            </template>

            <template #item.patient="{ item }">
              {{ item.invoice?.patient?.full_name ?? '—' }}
            </template>

            <template #item.method="{ item }">
              <v-chip size="small" :color="PAYMENT_METHOD_META[item.method]?.color" variant="tonal" :prepend-icon="PAYMENT_METHOD_META[item.method]?.icon">
                {{ PAYMENT_METHOD_META[item.method]?.label ?? item.method }}
              </v-chip>
            </template>

            <template #item.reference_number="{ item }">
              {{ item.reference_number || '—' }}
            </template>

            <template #item.amount="{ item }">
              <span class="font-weight-bold text-success">{{ formatCurrency(item.amount) }}</span>
            </template>

            <template #item.actions="{ item }">
              <v-btn
                size="small"
                variant="tonal"
                prepend-icon="mdi-printer-outline"
                @click="openPrintReceipt(item)"
              >
                Receipt
              </v-btn>
            </template>
          </v-data-table-server>
        </v-window-item>
      </v-window>
    </v-card>

    <InvoiceFormDialog
      v-model="createDialogOpen"
      :patients="patients"
      :services="services"
      :consultations="consultations"
      :appointments="appointments"
      @saved="onInvoiceSaved"
    />

    <PaymentFormDialog
      v-model="paymentDialogOpen"
      :invoice="activeInvoice"
      @recorded="onPaymentRecorded"
    />

    <PrintableInvoiceDialog
      v-model="printInvoiceDialogOpen"
      :invoice="activeInvoice"
    />

    <PrintableReceiptDialog
      v-model="printReceiptDialogOpen"
      :payment="activePayment"
    />
  </div>
</template>


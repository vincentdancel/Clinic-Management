<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PaymentFormDialog from '@/components/PaymentFormDialog.vue'
import PrintableInvoiceDialog from '@/components/PrintableInvoiceDialog.vue'
import PrintableReceiptDialog from '@/components/PrintableReceiptDialog.vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import {
  formatCurrency,
  formatDate,
  formatDateTime,
  INVOICE_STATUS_META,
  PAYMENT_METHOD_META,
  type InvoiceRecord,
  type PaymentRecord,
} from '@/types/billing'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const notifications = useNotificationsStore()

const canManage = computed(() => auth.hasPermission('billing.manage'))

const invoice = ref<InvoiceRecord | null>(null)
const loading = ref(false)
const cancelling = ref(false)

const paymentDialogOpen = ref(false)
const printInvoiceOpen = ref(false)
const printReceiptOpen = ref(false)
const selectedPayment = ref<PaymentRecord | null>(null)

async function loadInvoice() {
  loading.value = true
  try {
    const { data } = await api.get(`/invoices/${route.params.invoiceId}`)
    invoice.value = data.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load invoice details.'), 'error')
  } finally {
    loading.value = false
  }
}

async function cancelInvoice() {
  if (!invoice.value || cancelling.value) return
  cancelling.value = true
  try {
    const { data } = await api.post(`/invoices/${invoice.value.id}/cancel`)
    invoice.value = data.data
    notifications.show(data.message ?? 'Invoice cancelled.', 'success')
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not cancel invoice.'), 'error')
  } finally {
    cancelling.value = false
  }
}

function openReceipt(payment: PaymentRecord) {
  if (!invoice.value) return
  selectedPayment.value = {
    ...payment,
    invoice: {
      id: invoice.value.id,
      invoice_number: invoice.value.invoice_number,
      total_amount: invoice.value.total_amount,
      paid_amount: invoice.value.paid_amount,
      remaining_balance: invoice.value.remaining_balance,
      status: invoice.value.status,
      issued_at: invoice.value.issued_at,
      patient: invoice.value.patient,
    },
  }
  printReceiptOpen.value = true
}

function onPaymentRecorded(payload: { payment: PaymentRecord; invoice: InvoiceRecord }) {
  invoice.value = payload.invoice
  selectedPayment.value = payload.payment
  printReceiptOpen.value = true
}

onMounted(loadInvoice)
</script>

<template>
  <div class="d-flex flex-column ga-6">
    <div class="d-flex flex-wrap align-center justify-space-between ga-3">
      <div class="d-flex align-center ga-3">
        <v-btn icon="mdi-arrow-left" variant="text" @click="router.push('/billing')" />
        <div>
          <div class="d-flex align-center ga-2">
            <h1 class="text-h5 font-weight-bold">{{ invoice?.invoice_number ?? 'Invoice Details' }}</h1>
            <v-chip
              v-if="invoice"
              size="small"
              :color="INVOICE_STATUS_META[invoice.status]?.color"
              variant="tonal"
            >
              {{ INVOICE_STATUS_META[invoice.status]?.label ?? invoice.status }}
            </v-chip>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Issued {{ formatDate(invoice?.issued_at) }} · Due {{ formatDate(invoice?.due_at) }}
          </p>
        </div>
      </div>

      <div v-if="invoice" class="d-flex flex-wrap ga-2">
        <v-btn variant="outlined" prepend-icon="mdi-printer" @click="printInvoiceOpen = true">
          Print Invoice
        </v-btn>
        <v-btn
          v-if="canManage && invoice.status === 'unpaid' && invoice.paid_amount === 0"
          color="error"
          variant="tonal"
          :loading="cancelling"
          @click="cancelInvoice"
        >
          Cancel Invoice
        </v-btn>
        <v-btn
          v-if="canManage && (invoice.status === 'unpaid' || invoice.status === 'partially_paid')"
          color="primary"
          prepend-icon="mdi-cash-plus"
          @click="paymentDialogOpen = true"
        >
          Record Payment
        </v-btn>
      </div>
    </div>

    <v-progress-linear v-if="loading" indeterminate color="primary" />

    <template v-if="invoice">
      <v-row dense>
        <v-col cols="12" md="4">
          <v-card rounded="lg" variant="outlined" class="pa-4 bg-white h-100">
            <div class="text-caption text-medium-emphasis mb-1">PATIENT INFORMATION</div>
            <div class="text-subtitle-1 font-weight-bold">{{ invoice.patient?.full_name }}</div>
            <div class="text-body-2 text-medium-emphasis">{{ invoice.patient?.patient_number }}</div>
            <div v-if="invoice.patient?.phone" class="text-body-2 mt-2">{{ invoice.patient.phone }}</div>
            <div v-if="invoice.consultation" class="text-caption text-medium-emphasis mt-2">
              Consultation: {{ invoice.consultation.consultation_number }}
            </div>
          </v-card>
        </v-col>

        <v-col cols="12" md="8">
          <v-card rounded="lg" variant="outlined" class="pa-4 bg-white h-100">
            <v-row dense>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">SUBTOTAL</div>
                <div class="text-subtitle-1 font-weight-bold">{{ formatCurrency(invoice.subtotal) }}</div>
              </v-col>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">TOTAL AMOUNT</div>
                <div class="text-subtitle-1 font-weight-bold">{{ formatCurrency(invoice.total_amount) }}</div>
              </v-col>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">AMOUNT PAID</div>
                <div class="text-subtitle-1 font-weight-bold text-success">{{ formatCurrency(invoice.paid_amount) }}</div>
              </v-col>
              <v-col cols="6" sm="3">
                <div class="text-caption text-medium-emphasis">REMAINING BALANCE</div>
                <div class="text-subtitle-1 font-weight-bold text-primary">{{ formatCurrency(invoice.remaining_balance) }}</div>
              </v-col>
            </v-row>
            <div v-if="invoice.notes" class="text-body-2 text-medium-emphasis mt-3 pt-3 border-t">
              <b>Notes:</b> {{ invoice.notes }}
            </div>
          </v-card>
        </v-col>
      </v-row>

      <v-card rounded="lg" variant="outlined" class="bg-white">
        <v-card-title class="px-4 py-3 text-subtitle-1 font-weight-bold border-b">
          Itemized Charges
        </v-card-title>
        <v-table>
          <thead>
            <tr>
              <th>Description</th>
              <th>Category</th>
              <th class="text-right">Qty</th>
              <th class="text-right">Unit Price</th>
              <th class="text-right">Line Total</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, idx) in invoice.items ?? []" :key="item.id ?? idx">
              <td>{{ item.description }}</td>
              <td class="text-capitalize">{{ item.item_type }}</td>
              <td class="text-right">{{ item.quantity }}</td>
              <td class="text-right">{{ formatCurrency(item.unit_price) }}</td>
              <td class="text-right font-weight-medium">{{ formatCurrency(item.line_total) }}</td>
            </tr>
          </tbody>
        </v-table>
      </v-card>

      <v-card rounded="lg" variant="outlined" class="bg-white">
        <v-card-title class="px-4 py-3 text-subtitle-1 font-weight-bold border-b">
          Payment History
        </v-card-title>
        <v-table v-if="(invoice.payments ?? []).length > 0">
          <thead>
            <tr>
              <th>Receipt #</th>
              <th>Paid At</th>
              <th>Method</th>
              <th>Reference</th>
              <th>Received By</th>
              <th class="text-right">Amount</th>
              <th class="text-right">Receipt</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="payment in invoice.payments" :key="payment.id">
              <td class="font-weight-bold">{{ payment.receipt_number }}</td>
              <td>{{ formatDateTime(payment.paid_at) }}</td>
              <td>
                <v-chip size="small" :color="PAYMENT_METHOD_META[payment.method]?.color" variant="tonal">
                  {{ PAYMENT_METHOD_META[payment.method]?.label ?? payment.method }}
                </v-chip>
              </td>
              <td>{{ payment.reference_number || '—' }}</td>
              <td>{{ payment.receiver?.name ?? 'Clinic Staff' }}</td>
              <td class="text-right font-weight-bold text-success">{{ formatCurrency(payment.amount) }}</td>
              <td class="text-right">
                <v-btn size="small" variant="tonal" prepend-icon="mdi-printer-outline" @click="openReceipt(payment)">
                  Print
                </v-btn>
              </td>
            </tr>
          </tbody>
        </v-table>
        <div v-else class="pa-6 text-center text-body-2 text-medium-emphasis">
          No payments have been recorded for this invoice yet.
        </div>
      </v-card>
    </template>

    <PaymentFormDialog
      v-model="paymentDialogOpen"
      :invoice="invoice"
      @recorded="onPaymentRecorded"
    />

    <PrintableInvoiceDialog
      v-model="printInvoiceOpen"
      :invoice="invoice"
    />

    <PrintableReceiptDialog
      v-model="printReceiptOpen"
      :payment="selectedPayment"
    />
  </div>
</template>


<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'

interface PortalInvoice {
  id: number
  invoice_number: string
  issued_at: string
  total_amount: number
  remaining_balance: number
  status: string
}

interface PortalPayment {
  id: number
  receipt_number: string
  amount: number
  method: string
  paid_at: string
  status: string
}

const notifications = useNotificationsStore()
const loading = ref(true)
const invoices = ref<PortalInvoice[]>([])
const payments = ref<PortalPayment[]>([])

function formatMoney(value: number): string {
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value)
}

onMounted(async () => {
  loading.value = true
  try {
    const [invRes, pmtRes] = await Promise.all([
      api.get<{ data: PortalInvoice[] }>('/patient/invoices'),
      api.get<{ data: PortalPayment[] }>('/patient/payments'),
    ])
    invoices.value = invRes.data.data
    payments.value = pmtRes.data.data
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not load billing history.'), 'error')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section>
    <div v-if="loading" class="d-flex justify-center py-12"><v-progress-circular indeterminate color="primary" /></div>
    <v-row v-else>
      <v-col cols="12" md="6">
        <v-card rounded="xl" elevation="0" class="pa-5">
          <h2 class="text-h6 font-weight-bold mb-3">My Invoices</h2>
          <v-list v-if="invoices.length" density="compact">
            <v-list-item v-for="inv in invoices" :key="inv.id">
              <v-list-item-title>{{ inv.invoice_number }} · {{ formatMoney(inv.total_amount) }}</v-list-item-title>
              <v-list-item-subtitle>Balance: {{ formatMoney(inv.remaining_balance) }} · {{ inv.status }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <p v-else class="text-body-2 text-medium-emphasis">No invoices on record.</p>
        </v-card>
      </v-col>
      <v-col cols="12" md="6">
        <v-card rounded="xl" elevation="0" class="pa-5">
          <h2 class="text-h6 font-weight-bold mb-3">Payment History</h2>
          <v-list v-if="payments.length" density="compact">
            <v-list-item v-for="pmt in payments" :key="pmt.id">
              <v-list-item-title>{{ pmt.receipt_number }} · {{ formatMoney(pmt.amount) }}</v-list-item-title>
              <v-list-item-subtitle>{{ pmt.method }} · {{ pmt.status }}</v-list-item-subtitle>
            </v-list-item>
          </v-list>
          <p v-else class="text-body-2 text-medium-emphasis">No payments recorded.</p>
        </v-card>
      </v-col>
    </v-row>
  </section>
</template>

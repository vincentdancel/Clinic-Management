<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { api } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import {
  formatCurrency,
  type InvoiceRecord,
  type PaymentMethod,
  type PaymentRecord,
} from '@/types/billing'

const props = defineProps<{
  modelValue: boolean
  invoice: InvoiceRecord | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  recorded: [payload: { payment: PaymentRecord; invoice: InvoiceRecord }]
}>()

const notifications = useNotificationsStore()
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const idempotencyKey = ref('')

const form = reactive({
  amount: 0,
  method: 'cash' as PaymentMethod,
  reference_number: '',
  notes: '',
})

function generateKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `pay-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

watch(() => props.modelValue, (open) => {
  if (open && props.invoice) {
    form.amount = Number(props.invoice.remaining_balance ?? 0)
    form.method = 'cash'
    form.reference_number = ''
    form.notes = ''
    errors.value = {}
    idempotencyKey.value = generateKey()
  }
})

function setQuickAmount(fraction: number) {
  if (!props.invoice) return
  form.amount = Number((Number(props.invoice.remaining_balance) * fraction).toFixed(2))
}

async function submit() {
  if (!props.invoice || saving.value) return
  errors.value = {}
  saving.value = true

  try {
    const { data } = await api.post('/payments', {
      invoice_id: props.invoice.id,
      amount: Number(form.amount),
      method: form.method,
      reference_number: form.reference_number || null,
      notes: form.notes || null,
      idempotency_key: idempotencyKey.value,
    })

    notifications.show(data.message ?? 'Payment recorded successfully.', 'success')
    emit('recorded', { payment: data.data, invoice: data.invoice })
    emit('update:modelValue', false)
  } catch (error: any) {
    if (error.response?.status === 409) {
      notifications.show(error.response.data?.message ?? 'Duplicate payment prevented.', 'info')
      if (error.response.data?.invoice && error.response.data?.data) {
        emit('recorded', { payment: error.response.data.data, invoice: error.response.data.invoice })
      }
      emit('update:modelValue', false)
    } else if (error.response?.status === 422 && error.response.data?.errors) {
      errors.value = error.response.data.errors
    } else {
      notifications.show(error.response?.data?.message ?? 'Could not record payment.', 'error')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="540" persistent @update:model-value="emit('update:modelValue', $event)">
    <v-card v-if="invoice" rounded="lg">
      <v-card-title class="d-flex align-center justify-space-between px-6 py-4 border-b">
        <div>
          <div class="text-h6 font-weight-bold">Record Payment</div>
          <div class="text-caption text-medium-emphasis">{{ invoice.invoice_number }} · {{ invoice.patient?.full_name }}</div>
        </div>
        <v-btn icon="mdi-close" variant="text" :disabled="saving" @click="emit('update:modelValue', false)" />
      </v-card-title>

      <v-card-text class="pa-6">
        <div class="bg-grey-lighten-4 rounded-lg pa-4 mb-4">
          <div class="d-flex justify-space-between text-body-2 mb-1">
            <span class="text-medium-emphasis">Invoice Total</span>
            <span class="font-weight-medium">{{ formatCurrency(invoice.total_amount) }}</span>
          </div>
          <div class="d-flex justify-space-between text-body-2 mb-1">
            <span class="text-medium-emphasis">Already Paid</span>
            <span class="text-success font-weight-medium">{{ formatCurrency(invoice.paid_amount) }}</span>
          </div>
          <v-divider class="my-2" />
          <div class="d-flex justify-space-between text-subtitle-1 font-weight-bold">
            <span>Remaining Balance</span>
            <span class="text-primary">{{ formatCurrency(invoice.remaining_balance) }}</span>
          </div>
        </div>

        <div class="d-flex ga-2 mb-3">
          <v-btn size="small" variant="tonal" color="primary" @click="setQuickAmount(1)">Pay Full Balance</v-btn>
          <v-btn size="small" variant="tonal" color="secondary" @click="setQuickAmount(0.5)">50% Partial</v-btn>
        </div>

        <v-text-field
          v-model.number="form.amount"
          type="number"
          min="0.01"
          :max="invoice.remaining_balance"
          step="0.01"
          label="Payment Amount *"
          variant="outlined"
          density="comfortable"
          :error-messages="errors.amount ?? errors.invoice_id"
          class="mb-2"
        />

        <v-select
          v-model="form.method"
          :items="[
            { title: 'Cash', value: 'cash' },
            { title: 'Card', value: 'card' },
            { title: 'Bank Transfer', value: 'bank_transfer' },
            { title: 'Other', value: 'other' },
          ]"
          label="Payment Method *"
          variant="outlined"
          density="comfortable"
          :error-messages="errors.method"
          class="mb-2"
        />

        <v-text-field
          v-model="form.reference_number"
          label="Reference / Transaction Number (optional)"
          placeholder="e.g. Card approval code or bank ref #"
          variant="outlined"
          density="comfortable"
          :error-messages="errors.reference_number"
          class="mb-2"
        />

        <v-textarea
          v-model="form.notes"
          label="Payment Notes (optional)"
          rows="2"
          variant="outlined"
          density="comfortable"
        />
      </v-card-text>

      <v-card-actions class="px-6 py-4 border-t">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="emit('update:modelValue', false)">Cancel</v-btn>
        <v-btn color="primary" variant="flat" :loading="saving" :disabled="saving" @click="submit">
          Confirm Payment
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

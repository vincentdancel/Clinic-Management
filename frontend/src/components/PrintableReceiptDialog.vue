<script setup lang="ts">
import {
  formatCurrency,
  formatDateTime,
  PAYMENT_METHOD_META,
  type PaymentRecord,
} from '@/types/billing'

const props = defineProps<{
  modelValue: boolean
  payment: PaymentRecord | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

function printReceipt() {
  if (!props.payment) return
  const p = props.payment
  const inv = p.invoice
  const methodLabel = PAYMENT_METHOD_META[p.method]?.label ?? p.method
  const win = window.open('', '_blank', 'width=720,height=840')
  if (!win) return

  win.document.write(`<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Receipt ${p.receipt_number ?? ''}</title>
  <style>
    body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; margin: 32px; }
    .header { display: flex; justify-content: space-between; border-bottom: 2px solid #0f766e; padding-bottom: 16px; margin-bottom: 20px; }
    .clinic { font-size: 20px; font-weight: 700; color: #0f766e; }
    .sub { font-size: 12px; color: #6b7280; margin-top: 4px; }
    .doc-title { text-align: right; }
    .doc-title h1 { margin: 0; font-size: 20px; letter-spacing: 0.5px; }
    .doc-title p { margin: 4px 0 0; font-size: 13px; color: #4b5563; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; font-size: 13px; }
    .box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px; }
    .label { font-size: 11px; text-transform: uppercase; color: #6b7280; margin-bottom: 4px; }
    .value { font-weight: 600; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; }
    th, td { border-bottom: 1px solid #e5e7eb; padding: 10px 8px; text-align: left; }
    th { background: #f3f4f6; font-size: 11px; text-transform: uppercase; color: #4b5563; }
    .right { text-align: right; }
    .total-box { margin-top: 20px; border: 1px solid #d1fae5; background: #ecfdf5; border-radius: 6px; padding: 14px; display: flex; justify-content: space-between; font-size: 16px; font-weight: 700; color: #065f46; }
    .footer { margin-top: 36px; font-size: 12px; color: #6b7280; text-align: center; border-top: 1px dashed #d1d5db; padding-top: 16px; }
  </style>
</head>
<body>
  <div class="header">
    <div>
      <div class="clinic">CLINIC CARE MANAGEMENT</div>
      <div class="sub">Official Payment Receipt</div>
    </div>
    <div class="doc-title">
      <h1>PAYMENT RECEIPT</h1>
      <p>${p.receipt_number ?? '—'}</p>
    </div>
  </div>
  <div class="grid">
    <div class="box">
      <div class="label">Received From</div>
      <div class="value">${inv?.patient?.full_name ?? 'Patient'}</div>
      <div class="sub">${inv?.patient?.patient_number ?? ''}</div>
    </div>
    <div class="box">
      <div class="label">Payment Details</div>
      <div class="value">${formatDateTime(p.paid_at)}</div>
      <div class="sub">Method: ${methodLabel}${p.reference_number ? ` · Ref: ${p.reference_number}` : ''}</div>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th>Invoice #</th>
        <th>Received By</th>
        <th class="right">Invoice Total</th>
        <th class="right">Amount Paid</th>
        <th class="right">Remaining Balance</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>${inv?.invoice_number ?? '—'}</td>
        <td>${p.receiver?.name ?? 'Clinic Staff'}</td>
        <td class="right">${formatCurrency(inv?.total_amount ?? p.amount)}</td>
        <td class="right">${formatCurrency(p.amount)}</td>
        <td class="right">${formatCurrency(inv?.remaining_balance ?? 0)}</td>
      </tr>
    </tbody>
  </table>
  <div class="total-box">
    <span>Amount Received</span>
    <span>${formatCurrency(p.amount)}</span>
  </div>
  ${p.notes ? `<p style="font-size:12px;color:#4b5563;margin-top:16px;"><strong>Notes:</strong> ${p.notes}</p>` : ''}
  <div class="footer">Thank you for choosing Clinic Care. This serves as your official payment receipt.</div>
</body>
</html>`)
  win.document.close()
  win.focus()
  win.print()
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="620" @update:model-value="emit('update:modelValue', $event)">
    <v-card v-if="payment" rounded="lg">
      <v-card-title class="d-flex align-center justify-space-between px-6 py-4 border-b">
        <div class="d-flex align-center ga-2">
          <v-icon icon="mdi-receipt-text-outline" color="primary" />
          <span class="text-h6 font-weight-bold">Official Receipt</span>
        </div>
        <v-btn icon="mdi-close" variant="text" @click="emit('update:modelValue', false)" />
      </v-card-title>

      <v-card-text class="pa-6">
        <div class="d-flex justify-space-between align-start mb-4">
          <div>
            <div class="text-subtitle-1 font-weight-bold text-primary">CLINIC CARE</div>
            <div class="text-caption text-medium-emphasis">Official Payment Receipt</div>
          </div>
          <div class="text-right">
            <div class="text-subtitle-2 font-weight-bold">{{ payment.receipt_number }}</div>
            <div class="text-caption text-medium-emphasis">{{ formatDateTime(payment.paid_at) }}</div>
          </div>
        </div>

        <v-divider class="mb-4" />

        <v-row dense class="mb-4">
          <v-col cols="6">
            <div class="text-caption text-medium-emphasis">PATIENT</div>
            <div class="text-body-2 font-weight-bold">{{ payment.invoice?.patient?.full_name ?? '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ payment.invoice?.patient?.patient_number ?? '' }}</div>
          </v-col>
          <v-col cols="6" class="text-right">
            <div class="text-caption text-medium-emphasis">INVOICE NUMBER</div>
            <div class="text-body-2 font-weight-bold">{{ payment.invoice?.invoice_number ?? '—' }}</div>
            <div class="text-caption text-medium-emphasis">Received by: {{ payment.receiver?.name ?? 'Clinic Staff' }}</div>
          </v-col>
        </v-row>

        <div class="bg-grey-lighten-4 rounded-lg pa-4 mb-4">
          <div class="d-flex justify-space-between text-body-2 mb-2">
            <span class="text-medium-emphasis">Payment Method</span>
            <span class="font-weight-medium">{{ PAYMENT_METHOD_META[payment.method]?.label ?? payment.method }}</span>
          </div>
          <div v-if="payment.reference_number" class="d-flex justify-space-between text-body-2 mb-2">
            <span class="text-medium-emphasis">Reference Number</span>
            <span class="font-weight-medium">{{ payment.reference_number }}</span>
          </div>
          <div class="d-flex justify-space-between text-body-2 mb-2">
            <span class="text-medium-emphasis">Remaining Invoice Balance</span>
            <span class="font-weight-medium">{{ formatCurrency(payment.invoice?.remaining_balance ?? 0) }}</span>
          </div>
          <v-divider class="my-2" />
          <div class="d-flex justify-space-between text-subtitle-1 font-weight-bold">
            <span>Amount Paid</span>
            <span class="text-success">{{ formatCurrency(payment.amount) }}</span>
          </div>
        </div>

        <div v-if="payment.notes" class="text-caption text-medium-emphasis">
          <b>Notes:</b> {{ payment.notes }}
        </div>
      </v-card-text>

      <v-card-actions class="px-6 py-4 border-t">
        <v-spacer />
        <v-btn variant="text" @click="emit('update:modelValue', false)">Close</v-btn>
        <v-btn color="primary" variant="flat" prepend-icon="mdi-printer" @click="printReceipt">Print Receipt</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>


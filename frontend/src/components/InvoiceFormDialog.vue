<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import axios from 'axios'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import {
  formatCurrency,
  type BillingAppointmentOption,
  type BillingConsultationOption,
  type BillingPatientOption,
  type BillingServiceOption,
  type InvoiceItemType,
  type InvoiceRecord,
} from '@/types/billing'

interface DraftItem {
  item_type: InvoiceItemType
  service_id: number | null
  description: string
  quantity: number
  unit_price: number
}

const props = defineProps<{
  modelValue: boolean
  patients: BillingPatientOption[]
  services: BillingServiceOption[]
  consultations: BillingConsultationOption[]
  appointments: BillingAppointmentOption[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [invoice: InvoiceRecord]
}>()

const notifications = useNotificationsStore()
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const selectedServiceId = ref<number | null>(null)

const today = new Date().toISOString().slice(0, 10)

const form = reactive({
  patient_id: null as number | null,
  consultation_id: null as number | null,
  appointment_id: null as number | null,
  issued_at: today,
  due_at: '',
  discount_amount: 0,
  tax_amount: 0,
  notes: '',
  items: [] as DraftItem[],
})

function resetForm() {
  form.patient_id = null
  form.consultation_id = null
  form.appointment_id = null
  form.issued_at = new Date().toISOString().slice(0, 10)
  form.due_at = ''
  form.discount_amount = 0
  form.tax_amount = 0
  form.notes = ''
  form.items = [
    {
      item_type: 'consultation',
      service_id: null,
      description: 'General Consultation Fee',
      quantity: 1,
      unit_price: 500,
    },
  ]
  selectedServiceId.value = null
  errors.value = {}
}

watch(() => props.modelValue, (open) => {
  if (open) {
    resetForm()
  }
})

watch(() => form.consultation_id, (consultationId) => {
  if (!consultationId) return
  const match = props.consultations.find((c) => c.id === consultationId)
  if (!match) return
  form.patient_id = match.patient_id
  form.appointment_id = match.appointment_id

  const consultItem = form.items.find((item) => item.item_type === 'consultation')
  const doctorLabel = match.doctor_name ? ` (Dr. ${match.doctor_name})` : ''
  if (consultItem) {
    consultItem.description = `Consultation Fee${doctorLabel}`
  }
})

const subtotal = computed(() =>
  form.items.reduce((sum, item) => sum + Math.max(0, Number(item.quantity || 0) * Number(item.unit_price || 0)), 0),
)

const totalAmount = computed(() =>
  Math.max(0, subtotal.value - Number(form.discount_amount || 0) + Number(form.tax_amount || 0)),
)

function addConsultationFee() {
  form.items.push({
    item_type: 'consultation',
    service_id: null,
    description: 'Doctor Consultation Fee',
    quantity: 1,
    unit_price: 500,
  })
}

function addSelectedService() {
  if (!selectedServiceId.value) return
  const service = props.services.find((s) => s.id === selectedServiceId.value)
  if (!service) return

  form.items.push({
    item_type: 'service',
    service_id: service.id,
    description: service.name,
    quantity: 1,
    unit_price: service.price,
  })
  selectedServiceId.value = null
}

function addCustomCharge() {
  form.items.push({
    item_type: 'other',
    service_id: null,
    description: '',
    quantity: 1,
    unit_price: 0,
  })
}

function removeItem(index: number) {
  if (form.items.length <= 1) return
  form.items.splice(index, 1)
}

async function submit() {
  errors.value = {}
  if (!form.patient_id) {
    errors.value = { patient_id: ['Please select a patient.'] }
    return
  }

  saving.value = true
  try {
    const { data } = await api.post('/invoices', {
      patient_id: form.patient_id,
      consultation_id: form.consultation_id || null,
      appointment_id: form.appointment_id || null,
      issued_at: form.issued_at,
      due_at: form.due_at || null,
      discount_amount: Number(form.discount_amount || 0),
      tax_amount: Number(form.tax_amount || 0),
      notes: form.notes || null,
      items: form.items.map((item) => ({
        item_type: item.item_type,
        service_id: item.service_id,
        description: item.description,
        quantity: Number(item.quantity),
        unit_price: Number(item.unit_price),
      })),
    })

    notifications.show(data.message ?? 'Invoice generated successfully.', 'success')
    emit('saved', data.data)
    emit('update:modelValue', false)
  } catch (error: unknown) {
    if (axios.isAxiosError(error) && error.response?.status === 422 && error.response.data?.errors) {
      errors.value = error.response.data.errors
    } else {
      notifications.show(getApiErrorMessage(error, 'Could not generate invoice.'), 'error')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="860" persistent scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="lg">
      <v-card-title class="d-flex align-center justify-space-between px-6 py-4 border-b">
        <div>
          <div class="text-h6 font-weight-bold">Generate Patient Invoice</div>
          <div class="text-caption text-medium-emphasis">Invoice number is generated automatically upon saving</div>
        </div>
        <v-btn icon="mdi-close" variant="text" @click="emit('update:modelValue', false)" />
      </v-card-title>

      <v-card-text class="pa-6">
        <v-row dense>
          <v-col cols="12" md="6">
            <v-autocomplete
              v-model="form.patient_id"
              :items="patients"
              item-title="full_name"
              item-value="id"
              label="Patient *"
              variant="outlined"
              density="comfortable"
              :error-messages="errors.patient_id"
              clearable
            >
              <template #item="{ props: itemProps, item }">
                <v-list-item v-bind="itemProps" :subtitle="item.raw.patient_number" />
              </template>
            </v-autocomplete>
          </v-col>

          <v-col cols="12" md="6">
            <v-autocomplete
              v-model="form.consultation_id"
              :items="consultations"
              :item-title="(c) => `${c.consultation_number ?? 'CON'} — ${c.patient_name ?? 'Patient'}`"
              item-value="id"
              label="Link Consultation (optional)"
              variant="outlined"
              density="comfortable"
              :error-messages="errors.consultation_id"
              clearable
            />
          </v-col>

          <v-col cols="12" md="6">
            <v-text-field
              v-model="form.issued_at"
              type="date"
              label="Issued Date *"
              variant="outlined"
              density="comfortable"
              :error-messages="errors.issued_at"
            />
          </v-col>

          <v-col cols="12" md="6">
            <v-text-field
              v-model="form.due_at"
              type="date"
              label="Due Date (optional)"
              variant="outlined"
              density="comfortable"
              :error-messages="errors.due_at"
            />
          </v-col>
        </v-row>

        <v-divider class="my-4" />

        <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-3">
          <div class="text-subtitle-1 font-weight-bold">Charges &amp; Line Items</div>
          <div class="d-flex flex-wrap align-center ga-2">
            <v-btn size="small" variant="tonal" color="primary" prepend-icon="mdi-stethoscope" @click="addConsultationFee">
              + Consultation Fee
            </v-btn>
            <v-btn size="small" variant="tonal" color="secondary" prepend-icon="mdi-plus" @click="addCustomCharge">
              + Custom Item
            </v-btn>
          </div>
        </div>

        <v-row dense class="mb-3 align-center">
          <v-col cols="12" sm="9">
            <v-autocomplete
              v-model="selectedServiceId"
              :items="services"
              :item-title="(s) => `${s.name} (${formatCurrency(s.price)})`"
              item-value="id"
              label="Add Clinic Service Charge"
              variant="outlined"
              density="compact"
              hide-details
              clearable
            />
          </v-col>
          <v-col cols="12" sm="3">
            <v-btn block color="primary" variant="outlined" :disabled="!selectedServiceId" @click="addSelectedService">
              Add Service
            </v-btn>
          </v-col>
        </v-row>

        <v-alert v-if="errors.items" type="error" variant="tonal" density="compact" class="mb-3">
          {{ errors.items[0] }}
        </v-alert>

        <div class="border rounded-lg pa-3 mb-4">
          <v-row v-for="(item, index) in form.items" :key="index" dense class="align-center mb-2">
            <v-col cols="12" sm="2">
              <v-select
                v-model="item.item_type"
                :items="[
                  { title: 'Consultation', value: 'consultation' },
                  { title: 'Service', value: 'service' },
                  { title: 'Other', value: 'other' },
                ]"
                label="Type"
                variant="outlined"
                density="compact"
                hide-details
              />
            </v-col>
            <v-col cols="12" sm="4">
              <v-text-field
                v-model="item.description"
                label="Description *"
                variant="outlined"
                density="compact"
                hide-details
              />
            </v-col>
            <v-col cols="4" sm="2">
              <v-text-field
                v-model.number="item.quantity"
                type="number"
                min="0.001"
                step="1"
                label="Qty"
                variant="outlined"
                density="compact"
                hide-details
              />
            </v-col>
            <v-col cols="5" sm="2">
              <v-text-field
                v-model.number="item.unit_price"
                type="number"
                min="0"
                step="0.01"
                label="Unit Price"
                variant="outlined"
                density="compact"
                hide-details
              />
            </v-col>
            <v-col cols="3" sm="2" class="d-flex align-center justify-end ga-1">
              <span class="text-body-2 font-weight-medium">{{ formatCurrency(item.quantity * item.unit_price) }}</span>
              <v-btn
                icon="mdi-delete-outline"
                size="x-small"
                variant="text"
                color="error"
                :disabled="form.items.length <= 1"
                @click="removeItem(index)"
              />
            </v-col>
          </v-row>
        </div>

        <v-row dense>
          <v-col cols="12" md="6">
            <v-textarea
              v-model="form.notes"
              label="Notes (optional)"
              rows="3"
              variant="outlined"
              density="comfortable"
            />
          </v-col>
          <v-col cols="12" md="6">
            <v-row dense>
              <v-col cols="6">
                <v-text-field
                  v-model.number="form.discount_amount"
                  type="number"
                  min="0"
                  step="0.01"
                  label="Discount"
                  variant="outlined"
                  density="compact"
                  :error-messages="errors.discount_amount"
                />
              </v-col>
              <v-col cols="6">
                <v-text-field
                  v-model.number="form.tax_amount"
                  type="number"
                  min="0"
                  step="0.01"
                  label="Tax"
                  variant="outlined"
                  density="compact"
                  :error-messages="errors.tax_amount"
                />
              </v-col>
            </v-row>

            <div class="bg-grey-lighten-4 rounded-lg pa-3 mt-1">
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span class="text-medium-emphasis">Subtotal</span>
                <span class="font-weight-medium">{{ formatCurrency(subtotal) }}</span>
              </div>
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span class="text-medium-emphasis">Discount</span>
                <span>- {{ formatCurrency(form.discount_amount) }}</span>
              </div>
              <div class="d-flex justify-space-between text-body-2 mb-2">
                <span class="text-medium-emphasis">Tax</span>
                <span>+ {{ formatCurrency(form.tax_amount) }}</span>
              </div>
              <v-divider class="mb-2" />
              <div class="d-flex justify-space-between text-subtitle-1 font-weight-bold">
                <span>Total Amount</span>
                <span class="text-primary">{{ formatCurrency(totalAmount) }}</span>
              </div>
            </div>
          </v-col>
        </v-row>
      </v-card-text>

      <v-card-actions class="px-6 py-4 border-t">
        <v-spacer />
        <v-btn variant="text" :disabled="saving" @click="emit('update:modelValue', false)">Cancel</v-btn>
        <v-btn color="primary" variant="flat" :loading="saving" @click="submit">Generate Invoice</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>


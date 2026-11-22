<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { api, getApiErrorMessage } from '@/services/api'
import { useNotificationsStore } from '@/stores/notifications'
import type { DoctorOption, DoctorScheduleRecord } from '@/types/appointment'

const props = defineProps<{
  modelValue: boolean
  doctors: DoctorOption[]
  canManage: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  updated: []
}>()

const notifications = useNotificationsStore()
const selectedDoctorId = ref<number | null>(null)
const schedules = ref<DoctorScheduleRecord[]>([])
const busy = reactive({ loading: false, saving: false })

const days = [
  { title: 'Sunday', value: 0 },
  { title: 'Monday', value: 1 },
  { title: 'Tuesday', value: 2 },
  { title: 'Wednesday', value: 3 },
  { title: 'Thursday', value: 4 },
  { title: 'Friday', value: 5 },
  { title: 'Saturday', value: 6 },
]

const form = reactive({
  day_of_week: 1,
  starts_at: '09:00',
  ends_at: '17:00',
  slot_minutes: 30,
})

watch(
  () => props.modelValue,
  (isOpen) => {
    if (isOpen) {
      selectedDoctorId.value = props.doctors[0]?.id ?? null
      if (selectedDoctorId.value) void loadSchedules()
    }
  },
)

watch(selectedDoctorId, () => {
  if (props.modelValue && selectedDoctorId.value) void loadSchedules()
})

async function loadSchedules() {
  if (!selectedDoctorId.value) return
  busy.loading = true
  try {
    const response = await api.get<{ data: DoctorScheduleRecord[] }>('/appointments/doctor-schedules', {
      params: { doctor_id: selectedDoctorId.value },
    })
    schedules.value = response.data.data
  } catch {
    schedules.value = []
  } finally {
    busy.loading = false
  }
}

async function addSchedule() {
  if (!selectedDoctorId.value) return
  busy.saving = true
  try {
    await api.post('/appointments/doctor-schedules', {
      employee_id: selectedDoctorId.value,
      ...form,
    })
    notifications.show('Doctor schedule added.', 'success')
    await loadSchedules()
    emit('updated')
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not save schedule.'), 'error')
  } finally {
    busy.saving = false
  }
}

async function removeSchedule(id: number) {
  try {
    await api.delete(`/appointments/doctor-schedules/${id}`)
    notifications.show('Schedule removed.', 'success')
    await loadSchedules()
    emit('updated')
  } catch (error: unknown) {
    notifications.show(getApiErrorMessage(error, 'Could not remove schedule.'), 'error')
  }
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="720" scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card rounded="xl" class="patient-form-card">
      <div class="dialog-heading">
        <div>
          <p class="eyebrow">AVAILABILITY RULES</p>
          <h2>Doctor schedules</h2>
          <p>Configure weekly working hours for each doctor. Appointments outside configured hours are blocked.</p>
        </div>
        <v-btn icon="mdi-close" variant="text" aria-label="Close" @click="emit('update:modelValue', false)" />
      </div>
      <v-divider />
      <v-card-text class="patient-form-content">
        <v-select
          v-model="selectedDoctorId"
          :items="doctors"
          item-title="full_name"
          item-value="id"
          label="Select doctor"
          variant="outlined"
          density="comfortable"
        />

        <div v-if="busy.loading" class="clinical-empty">Loading schedules…</div>
        <div v-else-if="!schedules.length" class="clinical-empty">
          No custom schedule rules set. Default clinic hours (8:00 AM – 5:00 PM) apply.
        </div>
        <div v-else class="schedule-list mb-4">
          <div v-for="item in schedules" :key="item.id" class="schedule-row">
            <div>
              <b>{{ item.day_name }}</b>
              <span>{{ item.starts_at }} – {{ item.ends_at }} ({{ item.slot_minutes }} min slots)</span>
            </div>
            <v-btn v-if="canManage" icon="mdi-delete-outline" variant="text" size="small" color="error" @click="removeSchedule(item.id)" />
          </div>
        </div>

        <template v-if="canManage && selectedDoctorId">
          <v-divider class="my-4" />
          <h3>Add working hours</h3>
          <v-form @submit.prevent="addSchedule">
            <v-row dense>
              <v-col cols="12" sm="4">
                <v-select v-model="form.day_of_week" :items="days" label="Day" variant="outlined" density="comfortable" />
              </v-col>
              <v-col cols="4" sm="3">
                <v-text-field v-model="form.starts_at" type="time" label="Starts" variant="outlined" density="comfortable" />
              </v-col>
              <v-col cols="4" sm="3">
                <v-text-field v-model="form.ends_at" type="time" label="Ends" variant="outlined" density="comfortable" />
              </v-col>
              <v-col cols="4" sm="2">
                <v-text-field v-model.number="form.slot_minutes" type="number" min="10" max="120" label="Slot" variant="outlined" density="comfortable" />
              </v-col>
            </v-row>
            <div class="dialog-actions">
              <v-btn type="submit" color="primary" rounded="lg" :loading="busy.saving">Add schedule</v-btn>
            </div>
          </v-form>
        </template>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.schedule-list {
  display: grid;
  gap: 8px;
}
.schedule-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  border: 1px solid #eef2f6;
  border-radius: 10px;
}
.schedule-row b {
  display: block;
  color: #2c4054;
  font-size: 12px;
}
.schedule-row span {
  color: #7c8c9e;
  font-size: 11px;
}
</style>


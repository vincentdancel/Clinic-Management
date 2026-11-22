<script setup lang="ts">
import { formatReportValue, type ReportPayload } from '@/types/reports'

const props = defineProps<{
  modelValue: boolean
  report: ReportPayload | null
  preparedBy: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

function escapeHtml(input: string): string {
  return input
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
}

function printReport() {
  if (!props.report) return
  const r = props.report
  const win = window.open('', '_blank', 'width=1024,height=760')
  if (!win) return

  const kpisHtml = r.summary
    .map(
      (kpi) => `
      <div class="kpi">
        <div class="kpi-label">${escapeHtml(kpi.label)}</div>
        <div class="kpi-value">${escapeHtml(formatReportValue(kpi.value, kpi.format))}</div>
      </div>`,
    )
    .join('')

  const headersHtml = r.columns
    .map((col) => `<th class="${col.align === 'right' ? 'right' : ''}">${escapeHtml(col.label)}</th>`)
    .join('')

  const rowsHtml = r.rows
    .map(
      (row) => `
      <tr>
        ${r.columns
          .map(
            (col) =>
              `<td class="${col.align === 'right' ? 'right' : ''}">${escapeHtml(formatReportValue(row[col.key], col.format))}</td>`,
          )
          .join('')}
      </tr>`,
    )
    .join('')

  const rangeText =
    r.filters.date_from || r.filters.date_to
      ? `${r.filters.date_from ?? 'Start'} to ${r.filters.date_to ?? 'Today'}`
      : 'All Dates'

  win.document.write(`<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${escapeHtml(r.title)}</title>
  <style>
    body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; margin: 24px; font-size: 12px; }
    .header { display: flex; justify-content: space-between; border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 16px; }
    .clinic { font-size: 18px; font-weight: 700; color: #0f766e; }
    .sub { font-size: 11px; color: #6b7280; margin-top: 4px; }
    .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 16px; }
    .kpi { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; }
    .kpi-label { font-size: 10px; text-transform: uppercase; color: #64748b; }
    .kpi-value { font-size: 15px; font-weight: 700; margin-top: 4px; color: #0f172a; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    th { background: #0f766e; color: #fff; text-align: left; padding: 8px 6px; font-size: 10px; text-transform: uppercase; }
    td { border-bottom: 1px solid #e5e7eb; padding: 7px 6px; }
    tr:nth-child(even) td { background: #f9fafb; }
    .right { text-align: right; }
    .footer { margin-top: 20px; font-size: 10px; color: #6b7280; text-align: right; }
    @media print { body { margin: 12px; } }
  </style>
</head>
<body>
  <div class="header">
    <div>
      <div class="clinic">CLINIC CARE MANAGEMENT</div>
      <div class="sub">${escapeHtml(r.title)} · ${escapeHtml(r.description)}</div>
    </div>
    <div class="sub" style="text-align:right">
      <div>Range: <strong>${escapeHtml(rangeText)}</strong></div>
      <div>Prepared by: ${escapeHtml(props.preparedBy)}</div>
      <div>Generated: ${escapeHtml(new Date(r.generated_at).toLocaleString())}</div>
    </div>
  </div>
  <div class="kpis">${kpisHtml}</div>
  <table>
    <thead><tr>${headersHtml}</tr></thead>
    <tbody>${rowsHtml || `<tr><td colspan="${r.columns.length}" style="text-align:center;padding:20px;">No records found.</td></tr>`}</tbody>
  </table>
  <div class="footer">Total records: ${r.rows.length}</div>
  <script>window.onload = () => { window.print(); };</scr` + `ipt>
</body>
</html>`)
  win.document.close()
}
</script>

<template>
  <v-dialog :model-value="modelValue" max-width="960" scrollable @update:model-value="emit('update:modelValue', $event)">
    <v-card v-if="report" rounded="xl">
      <v-card-title class="d-flex align-center justify-space-between pa-5 border-b">
        <div>
          <div class="text-h6 font-weight-bold">{{ report.title }}</div>
          <div class="text-caption text-medium-emphasis">Print Preview · {{ report.rows.length }} record(s)</div>
        </div>
        <div class="d-flex ga-2">
          <v-btn color="primary" prepend-icon="mdi-printer" rounded="lg" @click="printReport">Print Now</v-btn>
          <v-btn icon="mdi-close" variant="text" @click="emit('update:modelValue', false)" />
        </div>
      </v-card-title>

      <v-card-text class="pa-5">
        <v-row dense class="mb-4">
          <v-col v-for="kpi in report.summary" :key="kpi.key" cols="6" sm="3">
            <v-sheet rounded="lg" border class="pa-3 bg-grey-lighten-5">
              <div class="text-caption text-uppercase text-medium-emphasis">{{ kpi.label }}</div>
              <div class="text-subtitle-1 font-weight-bold mt-1">{{ formatReportValue(kpi.value, kpi.format) }}</div>
            </v-sheet>
          </v-col>
        </v-row>

        <v-table density="compact" class="border rounded-lg">
          <thead>
            <tr>
              <th v-for="col in report.columns" :key="col.key" :class="{ 'text-right': col.align === 'right' }">
                {{ col.label }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!report.rows.length">
              <td :colspan="report.columns.length" class="text-center py-6 text-medium-emphasis">
                No records matched the selected filters.
              </td>
            </tr>
            <tr v-for="(row, idx) in report.rows.slice(0, 50)" :key="idx">
              <td v-for="col in report.columns" :key="col.key" :class="{ 'text-right': col.align === 'right' }">
                {{ formatReportValue(row[col.key], col.format) }}
              </td>
            </tr>
          </tbody>
        </v-table>
        <p v-if="report.rows.length > 50" class="text-caption text-medium-emphasis mt-2">
          Preview shows first 50 rows. Clicking "Print Now" will print all {{ report.rows.length }} rows.
        </p>
      </v-card-text>
    </v-card>
  </v-dialog>
</template>


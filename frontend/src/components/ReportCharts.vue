<script setup lang="ts">
import { computed, ref } from 'vue'
import type { ReportBreakdownChart, ReportTrendChart } from '@/types/reports'

const props = defineProps<{
  trend: ReportTrendChart
  breakdown: ReportBreakdownChart
  isCurrency?: boolean
}>()

const hoveredBarIndex = ref<number | null>(null)
const hoveredSliceIndex = ref<number | null>(null)

const palette = ['#0f766e', '#2563eb', '#d97706', '#e11d48', '#7c3aed', '#059669', '#0284c7', '#9333ea']

const maxTrendValue = computed(() => {
  const all = props.trend.series.flatMap((s) => s.data)
  return Math.max(...all, 1)
})

const linePoints = computed(() => {
  const data = props.trend.series[0]?.data ?? []
  if (data.length === 0) return ''
  const width = 560
  const height = 180
  const padX = 24
  const padY = 16
  const usableW = width - padX * 2
  const usableH = height - padY * 2
  return data
    .map((val, idx) => {
      const x = data.length === 1 ? width / 2 : padX + (idx / (data.length - 1)) * usableW
      const y = height - padY - (val / maxTrendValue.value) * usableH
      return `${x.toFixed(1)},${y.toFixed(1)}`
    })
    .join(' ')
})

const lineCoordinates = computed(() => {
  const data = props.trend.series[0]?.data ?? []
  const width = 560
  const height = 180
  const padX = 24
  const padY = 16
  const usableW = width - padX * 2
  const usableH = height - padY * 2
  return data.map((val, idx) => ({
    x: data.length === 1 ? width / 2 : padX + (idx / (data.length - 1)) * usableW,
    y: height - padY - (val / maxTrendValue.value) * usableH,
    value: val,
    label: props.trend.labels[idx] ?? '',
  }))
})

const breakdownTotal = computed(() => props.breakdown.items.reduce((sum, item) => sum + item.value, 0))

const donutSlices = computed(() => {
  const total = breakdownTotal.value
  if (total <= 0) return []
  const radius = 58
  const circumference = 2 * Math.PI * radius
  let offset = 0
  return props.breakdown.items.map((item, idx) => {
    const pct = item.value / total
    const dash = pct * circumference
    const slice = {
      ...item,
      color: palette[idx % palette.length],
      percent: (pct * 100).toFixed(1),
      dashArray: `${dash} ${circumference - dash}`,
      dashOffset: -offset,
    }
    offset += dash
    return slice
  })
})

function formatVal(val: number): string {
  if (props.isCurrency) {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(val)
  }
  return new Intl.NumberFormat('en-PH').format(val)
}
</script>

<template>
  <v-row dense class="mb-4">
    <v-col cols="12" lg="7">
      <v-card rounded="xl" elevation="0" class="pa-5 border h-100">
        <div class="d-flex align-center justify-space-between mb-3">
          <div>
            <h3 class="text-subtitle-1 font-weight-bold">{{ trend.title }}</h3>
            <p class="text-caption text-medium-emphasis">Interactive chart · hover items for exact values</p>
          </div>
          <div v-if="trend.series.length > 1" class="d-flex ga-3">
            <span v-for="(s, idx) in trend.series" :key="s.name" class="d-flex align-center ga-1 text-caption">
              <span class="legend-swatch" :style="{ background: palette[idx % palette.length] }"></span>
              {{ s.name }}
            </span>
          </div>
        </div>

        <div v-if="!trend.labels.length" class="empty-chart text-medium-emphasis">
          <v-icon icon="mdi-chart-bar" size="28" class="mb-2" />
          <span class="text-body-2">No chart data available for the selected filters.</span>
        </div>

        <div v-else-if="trend.type === 'line'" class="line-chart-wrap">
          <svg viewBox="0 0 560 180" class="w-100" style="height: 200px; overflow: visible">
            <line x1="24" y1="16" x2="536" y2="16" stroke="#e5e7eb" stroke-dasharray="3 3" />
            <line x1="24" y1="90" x2="536" y2="90" stroke="#e5e7eb" stroke-dasharray="3 3" />
            <line x1="24" y1="164" x2="536" y2="164" stroke="#cbd5e1" />
            <polyline fill="none" stroke="#0f766e" stroke-width="2.5" stroke-linejoin="round" :points="linePoints" />
            <g v-for="(pt, idx) in lineCoordinates" :key="idx">
              <circle
                :cx="pt.x"
                :cy="pt.y"
                :r="hoveredBarIndex === idx ? 6 : 4"
                fill="#0f766e"
                stroke="#ffffff"
                stroke-width="2"
                style="cursor: pointer"
                @mouseenter="hoveredBarIndex = idx"
                @mouseleave="hoveredBarIndex = null"
              />
            </g>
          </svg>
          <div v-if="hoveredBarIndex !== null && lineCoordinates[hoveredBarIndex]" class="chart-tooltip">
            <strong>{{ lineCoordinates[hoveredBarIndex]?.label }}:</strong>
            {{ formatVal(lineCoordinates[hoveredBarIndex]?.value ?? 0) }}
          </div>
          <div class="d-flex justify-space-between text-caption text-medium-emphasis px-2 mt-1">
            <span>{{ trend.labels[0] }}</span>
            <span v-if="trend.labels.length > 2">{{ trend.labels[Math.floor(trend.labels.length / 2)] }}</span>
            <span v-if="trend.labels.length > 1">{{ trend.labels[trend.labels.length - 1] }}</span>
          </div>
        </div>

        <div v-else class="bar-chart-wrap">
          <div class="bar-columns">
            <div
              v-for="(label, idx) in trend.labels"
              :key="`${label}-${idx}`"
              class="bar-col"
              @mouseenter="hoveredBarIndex = idx"
              @mouseleave="hoveredBarIndex = null"
            >
              <div v-if="hoveredBarIndex === idx" class="bar-popover">
                <div class="font-weight-bold">{{ label }}</div>
                <div v-for="s in trend.series" :key="s.name">{{ s.name }}: {{ formatVal(s.data[idx] ?? 0) }}</div>
              </div>
              <div class="bar-group">
                <div
                  v-for="(s, sIdx) in trend.series"
                  :key="s.name"
                  class="bar-rect"
                  :style="{
                    height: `${Math.max(((s.data[idx] ?? 0) / maxTrendValue) * 100, (s.data[idx] ?? 0) > 0 ? 6 : 2)}%`,
                    backgroundColor: palette[sIdx % palette.length],
                  }"
                />
              </div>
              <span class="bar-label text-truncate">{{ label }}</span>
            </div>
          </div>
        </div>
      </v-card>
    </v-col>

    <v-col cols="12" lg="5">
      <v-card rounded="xl" elevation="0" class="pa-5 border h-100">
        <div class="mb-3">
          <h3 class="text-subtitle-1 font-weight-bold">{{ breakdown.title }}</h3>
          <p class="text-caption text-medium-emphasis">Proportional distribution</p>
        </div>

        <div v-if="!donutSlices.length" class="empty-chart text-medium-emphasis">
          <v-icon icon="mdi-chart-donut" size="28" class="mb-2" />
          <span class="text-body-2">No distribution data for the selected filters.</span>
        </div>

        <div v-else class="d-flex flex-column flex-sm-row align-center ga-4">
          <div class="donut-container">
            <svg viewBox="0 0 150 150" class="donut-svg">
              <circle cx="75" cy="75" r="58" fill="none" stroke="#f1f5f9" stroke-width="20" />
              <circle
                v-for="(slice, idx) in donutSlices"
                :key="slice.label"
                cx="75"
                cy="75"
                r="58"
                fill="none"
                :stroke="slice.color"
                :stroke-width="hoveredSliceIndex === idx ? 24 : 20"
                :stroke-dasharray="slice.dashArray"
                :stroke-dashoffset="slice.dashOffset"
                transform="rotate(-90 75 75)"
                style="cursor: pointer; transition: stroke-width 0.15s ease"
                @mouseenter="hoveredSliceIndex = idx"
                @mouseleave="hoveredSliceIndex = null"
              />
            </svg>
            <div class="donut-center">
              <strong class="text-body-2 font-weight-bold">
                {{ hoveredSliceIndex !== null ? `${donutSlices[hoveredSliceIndex]?.percent}%` : formatVal(breakdownTotal) }}
              </strong>
              <span class="text-caption text-medium-emphasis text-truncate" style="max-width: 82px">
                {{ hoveredSliceIndex !== null ? donutSlices[hoveredSliceIndex]?.label : 'Total' }}
              </span>
            </div>
          </div>

          <div class="flex-grow-1 w-100 d-flex flex-column ga-2">
            <div
              v-for="(slice, idx) in donutSlices"
              :key="slice.label"
              class="d-flex align-center justify-space-between text-body-2 px-2 py-1 rounded-lg"
              :class="{ 'bg-grey-lighten-4': hoveredSliceIndex === idx }"
              style="cursor: pointer"
              @mouseenter="hoveredSliceIndex = idx"
              @mouseleave="hoveredSliceIndex = null"
            >
              <div class="d-flex align-center ga-2 text-truncate">
                <span class="legend-swatch" :style="{ background: slice.color }"></span>
                <span class="text-truncate">{{ slice.label }}</span>
              </div>
              <span class="font-weight-medium text-no-wrap ml-2">{{ formatVal(slice.value) }} ({{ slice.percent }}%)</span>
            </div>
          </div>
        </div>
      </v-card>
    </v-col>
  </v-row>
</template>

<style scoped>
.empty-chart {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 200px;
}
.legend-swatch {
  width: 10px;
  height: 10px;
  border-radius: 3px;
  display: inline-block;
  flex-shrink: 0;
}
.bar-chart-wrap {
  height: 220px;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
}
.bar-columns {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  height: 195px;
  padding-top: 24px;
  border-bottom: 1px solid #e2e8f0;
  overflow-x: auto;
}
.bar-col {
  flex: 1 1 0;
  min-width: 34px;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  position: relative;
}
.bar-group {
  width: 100%;
  height: calc(100% - 22px);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 3px;
}
.bar-rect {
  width: 100%;
  max-width: 24px;
  border-radius: 5px 5px 0 0;
  transition: opacity 0.15s ease, transform 0.15s ease;
}
.bar-col:hover .bar-rect {
  opacity: 0.85;
  transform: translateY(-2px);
}
.bar-label {
  font-size: 11px;
  color: #64748b;
  max-width: 68px;
  margin-top: 4px;
}
.bar-popover {
  position: absolute;
  top: -4px;
  background: #0f172a;
  color: #ffffff;
  font-size: 11px;
  padding: 4px 8px;
  border-radius: 6px;
  white-space: nowrap;
  z-index: 5;
  pointer-events: none;
}
.chart-tooltip {
  background: #0f172a;
  color: #fff;
  font-size: 12px;
  padding: 4px 10px;
  border-radius: 6px;
  display: inline-block;
  margin-top: 6px;
}
.donut-container {
  position: relative;
  width: 150px;
  height: 150px;
  flex-shrink: 0;
}
.donut-svg {
  width: 150px;
  height: 150px;
}
.donut-center {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  pointer-events: none;
}
</style>


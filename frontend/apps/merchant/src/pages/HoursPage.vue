<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import {
  ApiError, catalogApi, describeError, merchantApi, openingLabel,
  type Branch, type BranchHours, type HoursPeriod, type ScheduleException,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState } from '@sertec/ui'
import BranchPicker from '../components/BranchPicker.vue'
import { notify } from '../notify'
import { can } from '../session'

const DAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']

const branches = ref<Branch[]>([])
const branchId = ref('')
const hours = ref<BranchHours | null>(null)
const week = ref<{ opens_at: string; closes_at: string }[][]>(DAYS.map(() => []))
const exceptions = ref<ScheduleException[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const saving = ref(false)
const error = ref<string | null>(null)
const manage = can('branches.manage')

function apply(data: BranchHours) {
  hours.value = data
  week.value = DAYS.map((_, d) => data.weekly.filter((p) => p.weekday === d).map((p) => ({ opens_at: p.opens_at, closes_at: p.closes_at })))
  exceptions.value = data.exceptions.map((e) => ({ ...e }))
}

async function loadHours() {
  if (branchId.value) apply(await catalogApi.hours(branchId.value))
}

async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    branchId.value = branches.value[0]?.id ?? ''
    await loadHours()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

function copyToAll(day: number) {
  const source = week.value[day] ?? []
  week.value = week.value.map(() => source.map((p) => ({ ...p })))
}

function crossesMidnight(p: { opens_at: string; closes_at: string }) {
  return p.closes_at <= p.opens_at
}

async function save() {
  saving.value = true
  error.value = null
  const weekly: HoursPeriod[] = week.value.flatMap((periods, weekday) => periods.map((p) => ({ weekday, ...p })))
  try {
    apply(await catalogApi.saveHours(branchId.value, {
      weekly,
      exceptions: exceptions.value.map((e) => ({ ...e, opens_at: e.closed ? null : e.opens_at, closes_at: e.closed ? null : e.closes_at })),
    }))
    notify('Horários salvos')
  } catch (e) {
    error.value = describeError(e).fields ? 'Confira os horários (formato 18:00) e as datas.' : describeError(e).message
  } finally {
    saving.value = false
  }
}

watch(branchId, (id, old) => { if (old) void loadHours() })
onMounted(load)
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Horários</h1>
  <p v-if="hours" class="mb-6 text-sm font-medium" :class="hours.status.open ? 'text-success' : 'text-muted'">
    Agora: {{ openingLabel(hours.status, hours.timezone) }}
  </p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <form v-else class="grid max-w-3xl gap-8" @submit.prevent="save">
    <BranchPicker v-model="branchId" :branches="branches" />
    <SAlert v-if="error">{{ error }}</SAlert>

    <section>
      <h2 class="mb-1 font-semibold">Semana</h2>
      <p class="mb-3 text-sm text-muted">Se fechar depois da meia-noite, informe o horário normalmente (ex.: 18:00 às 02:00).</p>
      <ul class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
        <li v-for="(day, d) in DAYS" :key="day" class="grid gap-2 px-4 py-3 sm:grid-cols-[7rem_1fr]">
          <span class="pt-2 font-medium">{{ day }}</span>
          <div class="grid gap-2">
            <p v-if="week[d]!.length === 0" class="pt-2 text-sm text-muted">Fechado</p>
            <div v-for="(p, i) in week[d]" :key="i" class="flex flex-wrap items-center gap-2">
              <input v-model="p.opens_at" type="time" :aria-label="`${day}: abre`" class="min-h-10 rounded-lg border border-line px-2" :disabled="!manage">
              <span class="text-sm text-muted">às</span>
              <input v-model="p.closes_at" type="time" :aria-label="`${day}: fecha`" class="min-h-10 rounded-lg border border-line px-2" :disabled="!manage">
              <span v-if="crossesMidnight(p)" class="text-xs text-muted">termina no dia seguinte</span>
              <SButton v-if="manage" variant="ghost" @click="week[d]!.splice(i, 1)">Remover</SButton>
            </div>
            <div v-if="manage" class="flex flex-wrap gap-1">
              <SButton variant="ghost" @click="week[d]!.push({ opens_at: '18:00', closes_at: '23:00' })">Adicionar período</SButton>
              <SButton v-if="week[d]!.length" variant="ghost" @click="copyToAll(d)">Copiar para todos os dias</SButton>
            </div>
          </div>
        </li>
      </ul>
    </section>

    <section>
      <h2 class="mb-1 font-semibold">Feriados e datas especiais</h2>
      <p class="mb-3 text-sm text-muted">Valem só na data informada e substituem o horário da semana.</p>
      <ul class="grid gap-3">
        <li v-for="(e, i) in exceptions" :key="i" class="flex flex-wrap items-center gap-2 rounded-(--radius-card) border border-line bg-surface p-3">
          <input v-model="e.date" type="date" aria-label="Data" class="min-h-10 rounded-lg border border-line px-2">
          <label class="inline-flex items-center gap-2 text-sm"><input v-model="e.closed" type="checkbox" class="accent-brand-600"> Fechado o dia todo</label>
          <template v-if="!e.closed">
            <input v-model="e.opens_at" type="time" aria-label="Abre" class="min-h-10 rounded-lg border border-line px-2">
            <span class="text-sm text-muted">às</span>
            <input v-model="e.closes_at" type="time" aria-label="Fecha" class="min-h-10 rounded-lg border border-line px-2">
          </template>
          <input v-model="e.note" placeholder="Motivo (opcional)" aria-label="Motivo" class="min-h-10 min-w-40 flex-1 rounded-lg border border-line px-2">
          <SButton variant="ghost" @click="exceptions.splice(i, 1)">Remover</SButton>
        </li>
      </ul>
      <SButton v-if="manage" variant="secondary" class="mt-3" @click="exceptions.push({ date: '', closed: true, opens_at: '18:00', closes_at: '23:00', note: null })">
        Adicionar data
      </SButton>
    </section>

    <div v-if="manage" class="border-t border-line pt-6">
      <SButton type="submit" :loading="saving">Salvar horários</SButton>
    </div>
  </form>
</template>

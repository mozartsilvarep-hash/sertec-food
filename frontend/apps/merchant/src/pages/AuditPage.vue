<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, auditApi, formatDateTime, type AuditEntry } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import { auditDetail, auditLabel } from '../auditLabels'
import { me } from '../session'

const entries = ref<AuditEntry[]>([])
const cursor = ref<string | null>(null)
const filter = ref('')
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const loadingMore = ref(false)
const tz = computed(() => me.value?.tenant.timezone ?? 'America/Sao_Paulo')

const FILTERS = [
  { value: '', label: 'Tudo' },
  { value: 'order.', label: 'Pedidos' },
  { value: 'payment', label: 'Pagamentos' },
  { value: 'auth.', label: 'Acessos' },
  { value: 'products.', label: 'Produtos' },
  { value: 'availability.', label: 'Esgotados' },
]

async function load() {
  state.value = 'loading'
  try {
    const page = await auditApi.list(null, filter.value || null)
    entries.value = page.data
    cursor.value = page.meta.next_cursor
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

async function more() {
  if (!cursor.value) return
  loadingMore.value = true
  try {
    const page = await auditApi.list(cursor.value, filter.value || null)
    entries.value.push(...page.data)
    cursor.value = page.meta.next_cursor
  } finally {
    loadingMore.value = false
  }
}

function pick(value: string) {
  filter.value = value
  void load()
}

onMounted(load)
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Auditoria</h1>
  <p class="mb-6 max-w-2xl text-muted">Quem fez o quê no painel. Os registros não podem ser alterados nem apagados.</p>

  <div class="mb-4 flex flex-wrap gap-1.5" role="group" aria-label="Filtrar ações">
    <button
      v-for="f in FILTERS" :key="f.value" type="button" :aria-pressed="filter === f.value"
      class="rounded-full border px-3 py-1.5 text-sm font-medium"
      :class="filter === f.value ? 'border-ink bg-ink text-white' : 'border-line bg-surface text-muted hover:text-ink'"
      @click="pick(f.value)"
    >
      {{ f.label }}
    </button>
  </div>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <SPageState v-else-if="entries.length === 0" state="empty" title="Nada registrado" message="As ações aparecem aqui assim que acontecerem." />

  <ol v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
    <li v-for="e in entries" :key="e.id" class="grid gap-0.5 px-4 py-3 text-sm sm:grid-cols-[10rem_1fr_auto] sm:gap-4">
      <time class="text-muted tabular-nums" :datetime="e.at">{{ formatDateTime(e.at, tz) }}</time>
      <div>
        <p><strong>{{ e.actor?.name ?? 'Sistema' }}</strong> · {{ auditLabel(e.action) }}</p>
        <p v-if="auditDetail(e.metadata)" class="text-muted">{{ auditDetail(e.metadata) }}</p>
      </div>
      <span class="text-xs text-muted tabular-nums">{{ e.ip ?? '' }}</span>
    </li>
  </ol>
  <div v-if="cursor" class="mt-4 text-center">
    <SButton variant="secondary" :loading="loadingMore" @click="more">Carregar mais</SButton>
  </div>
</template>

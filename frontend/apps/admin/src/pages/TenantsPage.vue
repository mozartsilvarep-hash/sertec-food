<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, adminTenantsApi, type AdminTenantSummary, type TenantStatus } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import { me } from '../session'
import { STATUS_LABEL, STATUS_TONE } from '../labels'

const tenants = ref<AdminTenantSummary[]>([])
const cursor = ref<string | null>(null)
const q = ref('')
const status = ref<TenantStatus | ''>('')
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const superadmin = () => me.value?.user.platform_role === 'superadmin'

async function load() {
  state.value = 'loading'
  try {
    const page = await adminTenantsApi.list(q.value.trim() || null, status.value || null)
    tenants.value = page.data
    cursor.value = page.meta.next_cursor
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

async function more() {
  if (!cursor.value) return
  const page = await adminTenantsApi.list(q.value.trim() || null, status.value || null, cursor.value)
  tenants.value.push(...page.data)
  cursor.value = page.meta.next_cursor
}

onMounted(load)
</script>

<template>
  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Estabelecimentos</h1>
    <RouterLink v-if="superadmin()" to="/novo" class="rounded-(--radius-control) bg-brand-600 px-4 py-2.5 text-sm font-semibold text-brand-contrast hover:bg-brand-700">Novo estabelecimento</RouterLink>
  </div>

  <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="load">
    <label class="grid min-w-60 flex-1 gap-1 text-sm font-medium">Buscar
      <input v-model="q" type="search" placeholder="Nome ou endereço" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
    </label>
    <label class="grid gap-1 text-sm font-medium">Situação
      <select v-model="status" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
        <option value="">Todas</option>
        <option v-for="(label, key) in STATUS_LABEL" :key="key" :value="key">{{ label }}</option>
      </select>
    </label>
    <SButton type="submit">Filtrar</SButton>
  </form>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <SPageState v-else-if="tenants.length === 0" state="empty" title="Nenhum estabelecimento" message="Cadastre o primeiro em “Novo estabelecimento”." />

  <div v-else class="relative overflow-x-auto rounded-(--radius-card) border border-line bg-surface">
    <table class="w-full min-w-[46rem] text-sm">
      <thead class="border-b border-line text-left text-muted">
        <tr>
          <th scope="col" class="px-4 py-3 font-medium">Estabelecimento</th>
          <th scope="col" class="px-4 py-3 font-medium">Endereço</th>
          <th scope="col" class="px-4 py-3 font-medium">Situação</th>
          <th scope="col" class="px-4 py-3 font-medium">Plano</th>
          <th scope="col" class="px-4 py-3 font-medium">Mercado Pago</th>
          <th scope="col" class="px-4 py-3 text-right font-medium">Pedidos (30 dias)</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="t in tenants" :key="t.id" class="hover:bg-canvas">
          <td class="px-4 py-3"><RouterLink :to="`/estabelecimentos/${t.id}`" class="font-semibold text-ink hover:text-brand-700 hover:underline">{{ t.name }}</RouterLink></td>
          <td class="px-4 py-3 text-muted">{{ t.host }}</td>
          <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATUS_TONE[t.status]">{{ STATUS_LABEL[t.status] }}</span></td>
          <td class="px-4 py-3">{{ t.plan ?? '—' }}</td>
          <td class="px-4 py-3">{{ t.payments === 'live' ? 'Produção' : t.payments === 'test' ? 'Teste' : '—' }}</td>
          <td class="px-4 py-3 text-right tabular-nums">{{ t.orders_30d ?? 0 }}</td>
        </tr>
      </tbody>
    </table>
    <div v-if="cursor" class="border-t border-line p-3 text-center"><SButton variant="secondary" @click="more">Carregar mais</SButton></div>
  </div>
</template>

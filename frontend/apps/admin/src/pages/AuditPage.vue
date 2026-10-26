<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ApiError, adminTenantsApi, type PlatformAuditEntry } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'

const route = useRoute()
const entries = ref<PlatformAuditEntry[]>([])
const cursor = ref<string | null>(null)
const action = ref('')
const scope = ref<'' | 'platform' | 'tenants'>('')
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const tenant = () => (typeof route.query.tenant === 'string' ? route.query.tenant : null)

async function load(append = false) {
  if (!append) state.value = 'loading'
  try {
    const page = await adminTenantsApi.audit({ tenant: tenant(), action: action.value.trim() || null, scope: scope.value || null, cursor: append ? cursor.value : null })
    entries.value = append ? [...entries.value, ...page.data] : page.data
    cursor.value = page.meta.next_cursor
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

watch(() => route.query.tenant, () => load())
onMounted(() => load())
</script>

<template>
  <h1 class="mb-6 text-2xl font-bold">Auditoria</h1>
  <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="load()">
    <label class="grid gap-1 text-sm font-medium">Ação começa com
      <input v-model="action" placeholder="ex.: tenant. ou auth." class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
    </label>
    <label class="grid gap-1 text-sm font-medium">Origem
      <select v-model="scope" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
        <option value="">Tudo</option>
        <option value="platform">Só plataforma</option>
      </select>
    </label>
    <SButton type="submit">Filtrar</SButton>
    <RouterLink v-if="tenant()" to="/auditoria" class="text-sm text-brand-700 hover:underline">Remover filtro de estabelecimento</RouterLink>
  </form>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state !== 'ready'" :state="state === 'offline' ? 'offline' : 'error'" @retry="load()" />
  <SPageState v-else-if="entries.length === 0" state="empty" title="Nada registrado" />
  <div v-else class="overflow-x-auto rounded-(--radius-card) border border-line bg-surface">
    <table class="w-full min-w-[52rem] text-sm">
      <thead class="border-b border-line text-left text-muted">
        <tr><th scope="col" class="px-4 py-3 font-medium">Quando</th><th scope="col" class="px-4 py-3 font-medium">Quem</th><th scope="col" class="px-4 py-3 font-medium">Estabelecimento</th><th scope="col" class="px-4 py-3 font-medium">Ação</th><th scope="col" class="px-4 py-3 font-medium">Detalhes</th><th scope="col" class="px-4 py-3 font-medium">IP</th></tr>
      </thead>
      <tbody class="divide-y divide-line">
        <tr v-for="e in entries" :key="e.id" class="align-top">
          <td class="px-4 py-2.5 whitespace-nowrap tabular-nums">{{ new Date(e.at).toLocaleString('pt-BR') }}</td>
          <td class="px-4 py-2.5">{{ e.actor?.name ?? 'Sistema' }}<span v-if="e.actor" class="block text-xs text-muted">{{ e.actor.email }}</span></td>
          <td class="px-4 py-2.5">{{ e.tenant?.name ?? '—' }}</td>
          <td class="px-4 py-2.5 font-mono text-xs">{{ e.action }}</td>
          <td class="px-4 py-2.5 text-xs break-all text-muted">{{ e.metadata ? JSON.stringify(e.metadata) : '' }}</td>
          <td class="px-4 py-2.5 text-xs text-muted">{{ e.ip ?? '' }}</td>
        </tr>
      </tbody>
    </table>
    <div v-if="cursor" class="border-t border-line p-3 text-center"><SButton variant="secondary" @click="load(true)">Carregar mais</SButton></div>
  </div>
</template>

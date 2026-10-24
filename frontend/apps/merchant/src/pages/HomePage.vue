<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, merchantApi, type Branch } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import { can } from '../session'

const branches = ref<Branch[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline' | 'forbidden'>('loading')

async function load() {
  if (!can('branches.view')) {
    state.value = 'forbidden'
    return
  }
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

onMounted(load)
</script>

<template>
  <h1 class="mb-6 font-display text-2xl font-bold">Unidades</h1>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <SPageState v-else-if="state === 'forbidden'" state="empty" title="Sem acesso às unidades" message="Peça ao proprietário para ajustar suas permissões." />
  <SPageState v-else-if="branches.length === 0" state="empty" />
  <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
    <li v-for="branch in branches" :key="branch.id" class="flex items-center justify-between gap-4 px-4 py-3">
      <div>
        <p class="font-semibold text-ink">{{ branch.name }}</p>
        <p class="text-sm text-muted">{{ branch.phone ?? 'Sem telefone cadastrado' }}</p>
      </div>
      <span class="text-sm" :class="branch.active ? 'text-success' : 'text-muted'">{{ branch.active ? 'Ativa' : 'Inativa' }}</span>
    </li>
  </ul>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, catalogApi, type OptionGroup } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import { can } from '../../session'

const groups = ref<OptionGroup[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')

async function load() {
  state.value = 'loading'
  try {
    groups.value = await catalogApi.optionGroups()
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

function rule(g: OptionGroup): string {
  if (g.type === 'fraction') return `Sabores: até ${g.max_select}, cobra ${g.pricing_rule === 'average' ? 'a média' : 'o mais caro'}`
  const req = g.min_select > 0 ? `obrigatório (mín. ${g.min_select})` : 'opcional'
  return `Adicionais: ${req}, até ${g.max_select}${g.free_quantity ? `, ${g.free_quantity} grátis` : ''}`
}

onMounted(load)
</script>

<template>
  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-display text-2xl font-bold">Opções e sabores</h1>
    <RouterLink v-if="can('catalog.manage')" to="/opcoes/novo"><SButton tabindex="-1">Novo grupo</SButton></RouterLink>
  </div>
  <p class="mb-6 max-w-[65ch] text-sm text-muted">
    Grupos são reaproveitados entre produtos: crie "Bordas" uma vez e use em todas as pizzas.
  </p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <SPageState v-else-if="groups.length === 0" state="empty" title="Nenhum grupo ainda" message="Crie sabores de pizza, bordas, adicionais ou ponto da carne." />
  <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
    <li v-for="g in groups" :key="g.id">
      <RouterLink :to="`/opcoes/${g.id}`" class="block px-4 py-3 hover:bg-canvas">
        <span class="block font-medium" :class="!g.active && 'text-muted'">{{ g.name }}</span>
        <span class="block text-sm text-muted">{{ rule(g) }} · {{ g.options.length }} opções</span>
      </RouterLink>
    </li>
  </ul>
</template>

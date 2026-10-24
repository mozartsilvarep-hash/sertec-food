<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ApiError, catalogApi, describeError, type TenantSettings } from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextArea } from '@sertec/ui'
import { notify } from '../notify'
import { can } from '../session'

const settings = ref<TenantSettings | null>(null)
const description = ref('')
const color = ref('#c2410c')
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const saving = ref(false)
const error = ref<string | null>(null)
const manage = can('settings.update')

/** Mesmo cálculo do servidor (WCAG): contraste do branco sobre a cor. */
function contrastWithWhite(hex: string): number {
  const n = parseInt(hex.slice(1), 16)
  const lin = [(n >> 16) & 255, (n >> 8) & 255, n & 255]
    .map((c) => c / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  const l = 0.2126 * lin[0]! + 0.7152 * lin[1]! + 0.0722 * lin[2]!
  return 1.05 / (l + 0.05)
}

const contrast = computed(() => contrastWithWhite(color.value))
const readable = computed(() => contrast.value >= 4.5)

async function load() {
  state.value = 'loading'
  try {
    settings.value = await catalogApi.settings()
    description.value = settings.value.description ?? ''
    color.value = settings.value.brand_color ?? '#c2410c'
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

async function save() {
  saving.value = true
  error.value = null
  try {
    settings.value = await catalogApi.saveSettings({ description: description.value.trim() || null, brand_color: color.value })
    notify('Marca atualizada')
  } catch (e) {
    const d = describeError(e)
    error.value = d.fields?.brand_color?.[0] ?? d.message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <h1 class="mb-6 font-display text-2xl font-bold">Marca</h1>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <form v-else-if="settings" class="grid max-w-3xl gap-8 lg:grid-cols-[1fr_18rem]" @submit.prevent="save">
    <div class="grid content-start gap-6">
      <SAlert v-if="error">{{ error }}</SAlert>
      <STextArea v-model="description" label="Descrição no topo do cardápio" :maxlength="1000" hint="Uma frase que diga o que torna o seu lugar especial." />
      <div class="grid gap-1.5">
        <label for="brand" class="text-sm font-medium">Cor principal</label>
        <div class="flex items-center gap-3">
          <input id="brand" v-model="color" type="color" class="h-11 w-16 cursor-pointer rounded-lg border border-line bg-surface" :disabled="!manage">
          <code class="text-sm">{{ color }}</code>
        </div>
        <p class="text-sm" :class="readable ? 'text-success' : 'text-danger'">
          Contraste com texto branco: {{ contrast.toFixed(1) }}:1
          {{ readable ? '— boa leitura' : '— clara demais; escolha um tom mais escuro (mínimo 4,5:1)' }}
        </p>
      </div>
      <p class="text-sm text-muted">Nome e endereço do cardápio ({{ settings.slug }}) são alterados pela equipe SerTec.</p>
      <div v-if="manage"><SButton type="submit" :loading="saving" :disabled="!readable">Salvar marca</SButton></div>
    </div>

    <figure aria-label="Prévia do topo do cardápio" class="overflow-hidden rounded-2xl border border-line">
      <div class="px-5 pt-8 pb-5 text-white" :style="{ background: color }">
        <p class="font-display text-3xl leading-none font-extrabold">{{ settings.name }}</p>
        <p v-if="description" class="mt-2 text-sm text-white/85">{{ description }}</p>
      </div>
      <figcaption class="bg-surface px-5 py-3 text-xs text-muted">Prévia do cardápio</figcaption>
    </figure>
  </form>
</template>

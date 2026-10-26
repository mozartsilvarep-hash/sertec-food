<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { ApiError, describeError, formatMoney, merchantApi, merchantOrdersApi, type Branch, type DeliveryZoneInput } from '@sertec/api-client'
import { SAlert, SButton, SPageState, SSwitch, STextArea, STextField } from '@sertec/ui'
import BranchPicker from '../components/BranchPicker.vue'
import { notify } from '../notify'
import { can } from '../session'
import { addNeighborhood, ARACAJU_NEIGHBORHOODS, isBlankZone } from '../data/neighborhoods'

interface ZoneRow extends Omit<DeliveryZoneInput, 'neighborhoods' | 'minimum_order' | 'free_above'> {
  neighborhoods: string
  minimum_order: string
  free_above: string
}

const branches = ref<Branch[]>([])
const branchId = ref('')
const settings = ref({ pickup_enabled: true, delivery_enabled: false, pickup_eta_minutes: 30 })
const zones = ref<ZoneRow[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const manage = can('branches.manage')

const money = (v: string) => (v.trim() === '' ? '' : Number(v.replace(',', '.')).toFixed(2))

async function loadBranch() {
  if (!branchId.value) return
  const d = await merchantOrdersApi.delivery(branchId.value)
  settings.value = { pickup_enabled: d.pickup_enabled, delivery_enabled: d.delivery_enabled, pickup_eta_minutes: d.pickup_eta_minutes }
  zones.value = d.zones.map((z) => ({ ...z, neighborhoods: z.neighborhoods.join(', '), minimum_order: z.minimum_order ?? '', free_above: z.free_above ?? '' }))
}

async function load() {
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    branchId.value = branches.value[0]?.id ?? ''
    await loadBranch()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  message.value = null
  // Zona adicionada sem querer e deixada vazia: some ao salvar.
  zones.value = zones.value.filter((z) => !isBlankZone(z))
  try {
    await merchantOrdersApi.saveDelivery(branchId.value, {
      ...settings.value,
      zones: zones.value.map((z) => ({
        id: z.id ?? null,
        name: z.name,
        neighborhoods: z.neighborhoods.split(/[,\n]/).map((n) => n.trim()).filter(Boolean),
        fee: money(z.fee) || '0.00',
        minimum_order: money(z.minimum_order) || null,
        free_above: money(z.free_above) || null,
        eta_minutes: Number(z.eta_minutes) || 45,
        active: z.active,
      })),
    })
    await loadBranch()
    notify('Entrega e retirada salvas')
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = d.fields ? 'Confira os campos das zonas.' : d.message
  } finally {
    saving.value = false
  }
}

watch(branchId, (id, old) => { if (old) void loadBranch() })
onMounted(load)
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Entrega e retirada</h1>
  <p class="mb-6 max-w-[65ch] text-sm text-muted">O cliente escolhe o bairro no checkout. A taxa, o pedido mínimo e o prazo vêm da zona do bairro.</p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <form v-else class="grid max-w-3xl gap-8" @submit.prevent="save">
    <BranchPicker v-model="branchId" :branches="branches" />
    <SAlert v-if="message">{{ message }}</SAlert>

    <section class="grid gap-4 rounded-(--radius-card) border border-line bg-surface p-4">
      <SSwitch v-model="settings.pickup_enabled" label="Aceitar retirada no local" :disabled="!manage" />
      <div v-if="settings.pickup_enabled" class="w-48">
        <STextField :model-value="String(settings.pickup_eta_minutes)" label="Tempo para retirada (min)" @update:model-value="settings.pickup_eta_minutes = Number($event) || 30" />
      </div>
      <SSwitch v-model="settings.delivery_enabled" label="Fazer entregas" :disabled="!manage" />
    </section>

    <section v-if="settings.delivery_enabled">
      <h2 class="mb-3 font-semibold">Zonas de entrega</h2>
      <p v-if="zones.length === 0" class="mb-3 text-sm text-muted">Crie ao menos uma zona com os bairros atendidos.</p>
      <p v-else class="mb-3 text-sm text-muted">Zona deixada em branco é descartada ao salvar.</p>
      <ul class="grid gap-4">
        <li v-for="(z, i) in zones" :key="z.id ?? `n${i}`" class="grid gap-3 rounded-(--radius-card) border border-line bg-surface p-4">
          <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-48 flex-1"><STextField v-model="z.name" label="Nome da zona" placeholder="Ex.: Zona Sul" :error="errors[`zones.${i}.name`]?.[0]" /></div>
            <SSwitch v-model="z.active" label="Ativa" />
            <button
              v-if="manage"
              type="button"
              class="min-h-11 rounded-(--radius-control) border border-danger/40 px-3 text-sm font-semibold text-danger hover:bg-danger/5"
              :aria-label="`Remover zona ${z.name || i + 1}`"
              @click="zones.splice(i, 1)"
            >
              Remover
            </button>
          </div>
          <STextArea v-model="z.neighborhoods" label="Bairros (separados por vírgula)" :rows="2" :error="errors[`zones.${i}.neighborhoods`]?.[0]" hint="Ex.: Jardins, Grageru, 13 de Julho" />
          <details v-if="manage" class="text-sm">
            <summary class="cursor-pointer font-medium text-brand-700">Bairros de Aracaju (toque para adicionar)</summary>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button
                v-for="n in ARACAJU_NEIGHBORHOODS"
                :key="n"
                type="button"
                class="rounded-full border border-line px-2.5 py-1 hover:border-brand-600 hover:bg-brand-50"
                @click="z.neighborhoods = addNeighborhood(z.neighborhoods, n)"
              >
                {{ n }}
              </button>
            </div>
          </details>
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <STextField v-model="z.fee" label="Taxa (R$)" :error="errors[`zones.${i}.fee`]?.[0]" />
            <STextField v-model="z.minimum_order" label="Pedido mínimo (R$)" />
            <STextField v-model="z.free_above" label="Grátis acima de (R$)" />
            <STextField :model-value="String(z.eta_minutes)" label="Prazo (min)" @update:model-value="z.eta_minutes = Number($event) || 45" />
          </div>
          <p class="text-sm text-muted">
            Taxa {{ formatMoney(money(z.fee) || '0') }}<template v-if="z.minimum_order">, mínimo {{ formatMoney(money(z.minimum_order)) }}</template><template v-if="z.free_above">, grátis acima de {{ formatMoney(money(z.free_above)) }}</template>.
          </p>
        </li>
      </ul>
      <SButton v-if="manage" variant="secondary" class="mt-3" @click="zones.push({ name: '', neighborhoods: '', fee: '', minimum_order: '', free_above: '', eta_minutes: 45, active: true })">
        Adicionar zona
      </SButton>
    </section>

    <div v-if="manage" class="border-t border-line pt-6"><SButton type="submit" :loading="saving">Salvar</SButton></div>
  </form>
</template>

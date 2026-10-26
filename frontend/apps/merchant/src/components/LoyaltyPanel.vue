<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, describeError, formatDateTime, loyaltyApi, type LoyaltyCardView, type LoyaltyProgramInput } from '@sertec/api-client'
import { SAlert, SButton, SPageState, SSwitch, STextField } from '@sertec/ui'
import { notify } from '../notify'
import { me } from '../session'

/** Promoções → Fidelidade: regra do cartão e consulta/ajuste por celular. */
interface Form { active: boolean; stamps_required: number; stamps: string; min_order: string; reward_type: LoyaltyProgramInput['reward_type']; reward_amount: string; reward_percent: string; reward_max: string }
const form = reactive<Form>({
  active: false, stamps_required: 10, stamps: '10', min_order: '', reward_type: 'fixed', reward_amount: '', reward_percent: '', reward_max: '',
})
const label = ref<string | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'unavailable'>('loading')
const saving = ref(false)
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const err = (k: string) => errors.value[k]?.[0] ?? null
const br = (v: string | null) => (v ? v.replace('.', ',') : '')

async function load() {
  try {
    const p = await loyaltyApi.get()
    Object.assign(form, { ...p, stamps: String(p.stamps_required), min_order: br(p.min_order), reward_amount: br(p.reward_amount), reward_percent: br(p.reward_percent), reward_max: br(p.reward_max) })
    label.value = p.reward_label
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'feature_unavailable' ? 'unavailable' : 'error'
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    const p = await loyaltyApi.save({
      active: form.active, stamps_required: Number(form.stamps) || 0, reward_type: form.reward_type,
      min_order: form.min_order || null, reward_amount: form.reward_amount || null, reward_percent: form.reward_percent || null, reward_max: form.reward_max || null,
    })
    label.value = p.reward_label
    notify(p.active ? 'Cartão fidelidade ativo' : 'Cartão fidelidade salvo (desligado)')
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

/* Consulta e ajuste por celular */
const phone = ref('')
const card = ref<LoyaltyCardView | null>(null)
const adjust = reactive({ stamps: '1', note: '' })
const cardError = ref<string | null>(null)
const TYPE: Record<string, string> = { stamp: 'Selo de pedido', reward_used: 'Prêmio usado', reward_restored: 'Prêmio devolvido (pedido cancelado)', adjust: 'Ajuste manual' }

async function lookup() {
  cardError.value = null
  try {
    card.value = await loyaltyApi.card(phone.value)
  } catch (e) {
    card.value = null
    cardError.value = describeError(e).fields?.phone?.[0] ?? describeError(e).message
  }
}

async function applyAdjust(sign: 1 | -1) {
  cardError.value = null
  try {
    await loyaltyApi.adjust(phone.value, sign * (Number(adjust.stamps) || 1), adjust.note.trim() || null)
    notify(sign > 0 ? 'Selo adicionado' : 'Selo removido')
    adjust.note = ''
    await lookup()
  } catch (e) {
    cardError.value = describeError(e).message
  }
}

onMounted(load)
</script>

<template>
  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'unavailable'" state="empty" title="Fidelidade não incluída no seu plano" message="Fale com a SerTec para liberar." />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <div v-else class="grid max-w-3xl gap-8">
    <form class="grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5" novalidate aria-label="Regra do cartão fidelidade" @submit.prevent="save">
      <SAlert v-if="message">{{ message }}</SAlert>
      <SSwitch v-model="form.active" label="Cartão fidelidade ativo" />
      <p class="text-sm text-muted">O cliente ganha 1 selo por pedido <strong>concluído</strong> (pelo celular). Ao juntar os selos, ganha um prêmio para usar no próximo pedido pelo cardápio.</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <STextField v-model="form.stamps" label="Selos para ganhar o prêmio" inputmode="numeric" :error="err('stamps_required')" />
        <STextField v-model="form.min_order" label="Pedido mínimo para ganhar selo (R$, opcional)" inputmode="decimal" :error="err('min_order')" />
      </div>
      <fieldset class="grid gap-2">
        <legend class="mb-1 text-sm font-medium">Prêmio</legend>
        <div class="flex flex-wrap gap-4 text-sm">
          <label class="inline-flex items-center gap-2"><input v-model="form.reward_type" type="radio" value="fixed" class="accent-brand-600"> Valor em reais</label>
          <label class="inline-flex items-center gap-2"><input v-model="form.reward_type" type="radio" value="percent" class="accent-brand-600"> Percentual</label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <STextField v-if="form.reward_type === 'fixed'" v-model="form.reward_amount" label="Desconto (R$)" inputmode="decimal" :error="err('reward_amount')" />
          <template v-else>
            <STextField v-model="form.reward_percent" label="Desconto (%)" inputmode="decimal" :error="err('reward_percent')" />
            <STextField v-model="form.reward_max" label="Desconto máximo (R$, opcional)" inputmode="decimal" :error="err('reward_max')" />
          </template>
        </div>
      </fieldset>
      <p v-if="label" class="text-sm">Hoje: a cada <strong>{{ form.stamps }}</strong> pedidos, o cliente ganha <strong>{{ label }}</strong>.</p>
      <div><SButton type="submit" :loading="saving">Salvar</SButton></div>
    </form>

    <section class="grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5" aria-label="Cartão de um cliente">
      <h2 class="font-semibold">Cartão de um cliente</h2>
      <form class="flex flex-wrap items-end gap-2" @submit.prevent="lookup">
        <div class="w-56"><STextField v-model="phone" label="Celular do cliente" type="tel" inputmode="tel" placeholder="(79) 99999-0000" /></div>
        <SButton type="submit" variant="secondary">Consultar</SButton>
      </form>
      <SAlert v-if="cardError">{{ cardError }}</SAlert>
      <template v-if="card">
        <p class="text-lg"><strong>{{ card.stamps }}</strong><template v-if="card.required"> de {{ card.required }}</template> selos · <strong>{{ card.rewards }}</strong> {{ card.rewards === 1 ? 'prêmio' : 'prêmios' }} para usar</p>
        <div class="flex flex-wrap items-end gap-2">
          <div class="w-24"><STextField v-model="adjust.stamps" label="Selos" inputmode="numeric" /></div>
          <div class="min-w-48 flex-1"><STextField v-model="adjust.note" label="Motivo" placeholder="Ex.: compra no balcão" /></div>
          <SButton variant="secondary" @click="applyAdjust(1)">Dar selos</SButton>
          <SButton variant="ghost" @click="applyAdjust(-1)">Remover selos</SButton>
        </div>
        <ul v-if="card.history.length" class="divide-y divide-line text-sm">
          <li v-for="(h, i) in card.history" :key="i" class="flex flex-wrap justify-between gap-2 py-2">
            <span>{{ TYPE[h.type] ?? h.type }}<template v-if="h.order_number"> · pedido nº {{ h.order_number }}</template><template v-if="h.note"> · {{ h.note }}</template><template v-if="h.by"> · {{ h.by }}</template></span>
            <span class="text-muted">{{ formatDateTime(h.at, me?.tenant.timezone ?? 'America/Sao_Paulo') }}</span>
          </li>
        </ul>
      </template>
    </section>
  </div>
</template>

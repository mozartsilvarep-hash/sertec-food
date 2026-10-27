<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ApiError, describeError, paymentsApi, type MercadoPagoSettings } from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextField } from '@sertec/ui'
import { notify } from '../notify'

/**
 * Conta do Mercado Pago do restaurante. O token nunca volta do servidor:
 * aqui só aparecem os 4 últimos caracteres para conferência.
 */
const settings = ref<MercadoPagoSettings | null>(null)
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const editing = ref(false)
const token = ref('')
const secret = ref('')
const saving = ref(false)
const confirmDisconnect = ref(false)
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const copied = ref(false)

async function load() {
  state.value = 'loading'
  try {
    settings.value = await paymentsApi.settings()
    editing.value = !settings.value.connected
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    settings.value = await paymentsApi.connect({
      access_token: token.value.trim() || null,
      webhook_secret: secret.value.trim() || null,
    })
    token.value = ''
    secret.value = ''
    editing.value = false
    notify('Mercado Pago conectado')
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

async function disconnect() {
  if (!confirmDisconnect.value) {
    confirmDisconnect.value = true
    return
  }
  saving.value = true
  try {
    settings.value = await paymentsApi.disconnect()
    editing.value = true
    confirmDisconnect.value = false
    notify('Mercado Pago desconectado')
  } catch (e) {
    notify(describeError(e).message, 'error')
  } finally {
    saving.value = false
  }
}

async function copyWebhook() {
  if (!settings.value) return
  try {
    await navigator.clipboard.writeText(settings.value.webhook_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2500)
  } catch {
    /* sem permissão: o endereço continua visível para copiar à mão */
  }
}

onMounted(load)
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Pagamentos online</h1>
  <p class="mb-6 max-w-2xl text-muted">Receba Pix e cartão direto na sua conta do Mercado Pago. O dinheiro não passa pela SerTec; as taxas são as do seu contrato com o Mercado Pago.</p>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />

  <div v-else-if="settings" class="grid max-w-2xl gap-6">
    <section v-if="settings.connected" class="rounded-(--radius-card) border border-line bg-surface p-5" aria-label="Conta conectada">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="flex items-center gap-2 font-semibold">
            <span class="size-2.5 rounded-full bg-success" aria-hidden="true" /> Conectado: {{ settings.account_label }}
          </p>
          <p class="text-sm text-muted">Conta nº {{ settings.account_id }} · token terminando em {{ settings.token_hint }}</p>
        </div>
        <span
          class="rounded-full px-3 py-1 text-xs font-semibold"
          :class="settings.mode === 'test' ? 'bg-warning/15 text-warning' : 'bg-success/10 text-success'"
        >{{ settings.mode === 'test' ? 'Modo de teste' : 'Recebendo de verdade' }}</span>
      </div>
      <p v-if="settings.mode === 'test'" class="mt-3 text-sm text-muted">Com credenciais de teste nenhum valor é cobrado. Troque pelas de produção para começar a receber.</p>
      <div class="mt-4 flex flex-wrap gap-2">
        <SButton variant="secondary" @click="editing = !editing">{{ editing ? 'Fechar' : 'Trocar credenciais' }}</SButton>
        <SButton variant="ghost" :loading="saving && confirmDisconnect" @click="disconnect">{{ confirmDisconnect ? 'Confirmar: desconectar' : 'Desconectar' }}</SButton>
      </div>
      <p v-if="confirmDisconnect" class="mt-2 text-sm text-danger">Pix e cartão pelo site saem do cardápio até você conectar de novo.</p>
    </section>

    <form v-if="editing" class="grid grid-cols-[minmax(0,1fr)] gap-5 rounded-(--radius-card) border border-line bg-surface p-5" novalidate @submit.prevent="save">
      <SAlert v-if="message">{{ message }}</SAlert>
      <ol class="grid gap-1 text-sm text-muted [overflow-wrap:anywhere]">
        <li>1. Entre em <strong class="text-ink">mercadopago.com.br/developers</strong> › Suas integrações › sua aplicação.</li>
        <li>2. Em <strong class="text-ink">Credenciais de produção</strong> (ou de teste), copie o <strong class="text-ink">Access Token</strong>.</li>
      </ol>
      <STextField
        v-model="token"
        :label="settings.connected ? 'Novo Access Token (deixe em branco para manter)' : 'Access Token'"
        type="password"
        autocomplete="off"
        :error="errors.access_token?.[0] ?? null"
      />
      <STextField
        v-model="secret"
        label="Assinatura secreta do webhook (recomendado)"
        type="password"
        autocomplete="off"
        :error="errors.webhook_secret?.[0] ?? null"
      />
      <div class="grid grid-cols-[minmax(0,1fr)] gap-1.5 text-sm">
        <p class="text-muted">Em Webhooks, cadastre este endereço com o evento <strong class="text-ink">Pagamentos</strong> e copie a assinatura secreta gerada:</p>
        <div class="flex items-center gap-2">
          <code class="min-w-0 flex-1 truncate rounded-lg bg-canvas px-3 py-2">{{ settings.webhook_url }}</code>
          <SButton variant="secondary" @click="copyWebhook">{{ copied ? 'Copiado!' : 'Copiar' }}</SButton>
        </div>
        <p class="text-muted">Com a assinatura, avisos falsos são recusados. Sem ela, o sistema ainda confere cada pagamento direto no Mercado Pago.</p>
      </div>
      <div><SButton type="submit" :loading="saving && !confirmDisconnect">{{ settings.connected ? 'Salvar' : 'Conectar Mercado Pago' }}</SButton></div>
    </form>
  </div>
</template>

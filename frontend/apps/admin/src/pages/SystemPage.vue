<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { adminApi, ApiError, type MailStatus } from '@sertec/api-client'
import { SAlert, SButton, SPageState } from '@sertec/ui'
import { me } from '../session'

const superadmin = () => me.value?.user.platform_role === 'superadmin'

// Configuração de e-mail (lida do .env do servidor) e teste de envio.
const mail = ref<MailStatus | null>(null)
const state = ref<'loading' | 'ready' | 'error'>('loading')
const sending = ref(false)
const result = ref<{ ok: boolean; text: string } | null>(null)

async function load() {
  state.value = 'loading'
  try {
    mail.value = await adminApi.mailStatus()
    state.value = 'ready'
  } catch {
    state.value = 'error'
  }
}

async function test() {
  sending.value = true
  result.value = null
  try {
    result.value = { ok: true, text: await adminApi.mailTest() }
  } catch (e) {
    result.value = { ok: false, text: e instanceof ApiError ? e.message : 'Não foi possível enviar.' }
  } finally {
    sending.value = false
  }
}

onMounted(load)
</script>

<template>
  <h1 class="mb-2 text-2xl font-bold">Sistema</h1>
  <p class="mb-6 max-w-[65ch] text-muted">Envio de e-mails usado em "Esqueci a senha" e nos avisos do sistema.</p>
  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'error' || !mail" state="error" @retry="load" />
  <section v-else class="grid max-w-2xl gap-4 rounded-(--radius-card) border border-line bg-surface p-5" aria-label="E-mail">
    <h2 class="text-lg font-semibold">E-mail</h2>
    <dl class="grid grid-cols-[7rem_minmax(0,1fr)] gap-x-4 [overflow-wrap:anywhere] sm:grid-cols-[9rem_minmax(0,1fr)] gap-y-2 text-sm">
      <dt class="text-muted">Situação</dt>
      <dd :class="mail.configured ? 'font-semibold text-success' : 'font-semibold text-danger'">{{ mail.configured ? 'Configurado (SMTP)' : 'Não configurado' }}</dd>
      <dt class="text-muted">Servidor</dt><dd>{{ mail.host ? `${mail.host}:${mail.port}` : '—' }}</dd>
      <dt class="text-muted">Remetente</dt><dd>{{ mail.from_name }} &lt;{{ mail.from }}&gt;</dd>
    </dl>
    <SAlert v-if="!mail.configured">
      Os e-mails não estão saindo. Preencha as variáveis MAIL_* no arquivo .env.production do servidor e reinicie o sistema (docs/OPERATIONS.md, "E-mail").
    </SAlert>
    <div v-if="superadmin()">
      <SButton :loading="sending" @click="test">Enviar e-mail de teste para mim</SButton>
    </div>
    <p v-if="result" role="status" class="rounded-(--radius-control) p-3 text-sm" :class="result.ok ? 'bg-brand-50' : 'bg-danger-soft text-danger'">{{ result.text }}</p>
  </section>
</template>

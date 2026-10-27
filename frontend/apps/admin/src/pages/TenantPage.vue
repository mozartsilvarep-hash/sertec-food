<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  adminTenantsApi, describeError, type AdminTenant, type DomainInstructions, type TenantStatus,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState } from '@sertec/ui'
import { me } from '../session'
import { STATUS_LABEL, STATUS_TONE } from '../labels'
import TenantBilling from '../components/TenantBilling.vue'

const props = defineProps<{ id: string }>()
const tenant = ref<AdminTenant | null>(null)
const failed = ref(false)
const busy = ref<string | null>(null)
const error = ref<string | null>(null)
const ok = ref<string | null>(null)
const newPassword = ref<{ email: string; password: string } | null>(null)
const confirmReset = ref(false)
const hostname = ref('')
const instructions = ref<DomainInstructions | null>(null)
const superadmin = computed(() => me.value?.user.platform_role === 'superadmin')
const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—')

async function load() {
  try {
    tenant.value = await adminTenantsApi.get(props.id)
  } catch {
    failed.value = true
  }
}

async function act(key: string, fn: () => Promise<unknown>, success?: string) {
  busy.value = key
  error.value = null
  ok.value = null
  try {
    await fn()
    if (success) ok.value = success
    await load()
  } catch (e) {
    error.value = describeError(e).message
  } finally {
    busy.value = null
  }
}

const setStatus = (status: TenantStatus) =>
  act(`status-${status}`, async () => { tenant.value = await adminTenantsApi.update(props.id, { status }) }, `Situação: ${STATUS_LABEL[status]}`)


async function resetPassword() {
  if (!confirmReset.value) {
    confirmReset.value = true
    return
  }
  await act('reset', async () => { newPassword.value = await adminTenantsApi.resetOwnerPassword(props.id) })
  confirmReset.value = false
}

const addDomain = () =>
  act('domain', async () => {
    instructions.value = await adminTenantsApi.addDomain(props.id, hostname.value)
    hostname.value = ''
  })

const verify = (domainId: string) =>
  act(`verify-${domainId}`, async () => { await adminTenantsApi.verifyDomain(props.id, domainId) }, 'Domínio verificado')

const newToken = (domainId: string) =>
  act(`token-${domainId}`, async () => { instructions.value = await adminTenantsApi.domainToken(props.id, domainId) })

const makePrimary = (domainId: string) =>
  act(`primary-${domainId}`, () => adminTenantsApi.primaryDomain(props.id, domainId), 'Domínio principal alterado')

const removeDomain = (domainId: string) =>
  act(`rm-${domainId}`, () => adminTenantsApi.removeDomain(props.id, domainId), 'Domínio removido')

onMounted(async () => {
  await load()
})
</script>

<template>
  <RouterLink to="/" class="text-sm text-brand-700 hover:underline">‹ Estabelecimentos</RouterLink>
  <SPageState v-if="failed" state="error" title="Estabelecimento não encontrado" />
  <SPageState v-else-if="!tenant" state="loading" />

  <template v-else>
    <div class="mt-2 mb-6 flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-bold">{{ tenant.name }}</h1>
      <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="STATUS_TONE[tenant.status]">{{ STATUS_LABEL[tenant.status] }}</span>
    </div>

    <SAlert v-if="error" class="mb-4">{{ error }}</SAlert>
    <p v-if="ok" class="mb-4 text-sm font-medium text-success" role="status">{{ ok }}</p>

    <div class="grid gap-6 lg:grid-cols-2">
      <section class="rounded-(--radius-card) border border-line bg-surface p-5" aria-labelledby="h-geral">
        <h2 id="h-geral" class="mb-3 font-semibold">Geral</h2>
        <dl class="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-2 text-sm">
          <dt class="text-muted">Cardápio</dt><dd><a :href="`https://${tenant.host}`" target="_blank" rel="noopener" class="text-brand-700 underline">{{ tenant.host }}</a></dd>
          <dt class="text-muted">Proprietário</dt><dd>{{ tenant.owner?.name }}<br><span class="text-muted">{{ tenant.owner?.email }}</span></dd>
          <dt class="text-muted">Último acesso</dt><dd>{{ fmt(tenant.owner?.last_login_at ?? null) }}</dd>
          <dt class="text-muted">Equipe ativa</dt><dd>{{ tenant.staff_count }}</dd>
          <dt class="text-muted">Pedidos</dt><dd>{{ tenant.orders_total }} · último em {{ fmt(tenant.last_order_at) }}</dd>
          <dt class="text-muted">Mercado Pago</dt><dd>{{ tenant.payments ? `${tenant.payments.account_label} (${tenant.payments.mode === 'live' ? 'produção' : 'teste'})` : 'Não conectado' }}</dd>
          <dt class="text-muted">Fuso</dt><dd>{{ tenant.timezone }}</dd>
          <dt class="text-muted">Criado em</dt><dd>{{ fmt(tenant.created_at) }}</dd>
        </dl>
      </section>

      <section class="rounded-(--radius-card) border border-line bg-surface p-5" aria-labelledby="h-gestao">
        <h2 id="h-gestao" class="mb-3 font-semibold">Gestão</h2>
        <template v-if="superadmin">
          <p class="mb-2 text-sm text-muted">Situação</p>
          <div class="mb-5 flex flex-wrap gap-2">
            <SButton v-if="tenant.status !== 'active' && tenant.status !== 'cancelled'" :loading="busy === 'status-active'" @click="setStatus('active')">Ativar</SButton>
            <SButton v-if="tenant.status === 'active'" variant="secondary" :loading="busy === 'status-suspended'" @click="setStatus('suspended')">Suspender</SButton>
          </div>
          <p class="mb-5 text-sm text-muted">Plano, período e valor ficam em “Mensalidade”, abaixo.</p>
          <p class="mb-2 text-sm text-muted">Acesso do proprietário</p>
          <SButton variant="secondary" :loading="busy === 'reset'" @click="resetPassword">{{ confirmReset ? 'Confirmar: gerar nova senha' : 'Gerar nova senha do proprietário' }}</SButton>
          <p v-if="confirmReset" class="mt-2 text-sm text-danger">A senha atual deixa de funcionar e as sessões abertas são encerradas.</p>
          <div v-if="newPassword" class="mt-3 rounded-lg bg-canvas p-3 text-sm" aria-live="polite">
            Nova senha de {{ newPassword.email }} (aparece só agora):
            <code class="mt-1 block text-base font-bold tracking-wider">{{ newPassword.password }}</code>
          </div>
        </template>
        <p v-else class="text-sm text-muted">Somente administradores alteram estabelecimentos.</p>
      </section>

      <TenantBilling :tenant-id="tenant.id" :superadmin="superadmin" @changed="load" />

      <section class="rounded-(--radius-card) border border-line bg-surface p-5 lg:col-span-2" aria-labelledby="h-dominios">
        <h2 id="h-dominios" class="mb-3 font-semibold">Domínios</h2>
        <ul class="divide-y divide-line text-sm">
          <li v-for="d in tenant.domains" :key="d.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
            <span>
              <strong>{{ d.hostname }}</strong>
              <span v-if="d.is_primary" class="ml-2 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">principal</span>
              <span class="ml-2 text-xs" :class="d.verified ? 'text-success' : 'text-warning'">{{ d.verified ? 'verificado' : 'aguardando verificação' }}</span>
              <span class="ml-2 text-xs text-muted">{{ d.type === 'custom' ? 'próprio' : 'plataforma' }}</span>
            </span>
            <span v-if="superadmin && d.type === 'custom'" class="flex flex-wrap gap-2">
              <SButton v-if="!d.verified" variant="secondary" :loading="busy === `verify-${d.id}`" @click="verify(d.id)">Verificar DNS</SButton>
              <SButton v-if="!d.verified" variant="ghost" :loading="busy === `token-${d.id}`" @click="newToken(d.id)">Ver instruções</SButton>
              <SButton v-if="d.verified && !d.is_primary" variant="secondary" :loading="busy === `primary-${d.id}`" @click="makePrimary(d.id)">Tornar principal</SButton>
              <SButton v-if="!d.is_primary" variant="ghost" @click="removeDomain(d.id)">Remover</SButton>
            </span>
          </li>
        </ul>

        <div v-if="instructions" class="mt-4 rounded-lg border border-brand-600 bg-brand-50 p-4 text-sm" aria-live="polite">
          <p class="font-semibold">Peça ao cliente para criar estes registros no DNS de {{ instructions.hostname }}:</p>
          <table class="mt-2 w-full text-left">
            <thead class="text-muted"><tr><th scope="col" class="pr-3">Tipo</th><th scope="col" class="pr-3">Nome</th><th scope="col">Valor</th></tr></thead>
            <tbody class="font-mono text-xs">
              <tr><td class="pr-3 py-1">TXT</td><td class="pr-3 break-all">{{ instructions.txt_name }}</td><td class="break-all">{{ instructions.txt_value }}</td></tr>
              <tr><td class="pr-3 py-1">CNAME</td><td class="pr-3 break-all">{{ instructions.hostname }}</td><td class="break-all">{{ instructions.cname_target }}</td></tr>
            </tbody>
          </table>
          <p class="mt-2 text-muted">Depois clique em “Verificar DNS”. O certificado HTTPS é emitido automaticamente no primeiro acesso após a verificação.</p>
        </div>

        <form v-if="superadmin" class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="addDomain">
          <label class="grid min-w-64 flex-1 gap-1 text-sm font-medium">Adicionar domínio próprio
            <input v-model="hostname" placeholder="pedidos.restaurante.com.br" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
          </label>
          <SButton type="submit" :loading="busy === 'domain'" :disabled="!hostname.trim()">Adicionar</SButton>
        </form>
      </section>

      <section class="rounded-(--radius-card) border border-line bg-surface p-5 lg:col-span-2" aria-labelledby="h-unidades">
        <h2 id="h-unidades" class="mb-3 font-semibold">Unidades</h2>
        <ul class="grid gap-1 text-sm">
          <li v-for="b in tenant.branches" :key="b.id">{{ b.name }} <span class="text-muted">· {{ b.phone ?? 'sem telefone' }} · {{ b.active ? 'ativa' : 'inativa' }}</span></li>
        </ul>
        <RouterLink :to="`/auditoria?tenant=${tenant.id}`" class="mt-4 inline-block text-sm font-semibold text-brand-700 hover:underline">Ver auditoria deste estabelecimento ›</RouterLink>
      </section>
    </div>
  </template>
</template>

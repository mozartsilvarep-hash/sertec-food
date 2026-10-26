<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import {
  ApiError, STAFF_ROLES, describeError, merchantApi, teamApi, type Branch, type StaffMember,
} from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextField } from '@sertec/ui'
import { notify } from '../notify'

const members = ref<StaffMember[]>([])
const branches = ref<Branch[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const adding = ref(false)
const form = reactive({ name: '', email: '', role: 'attendant', branch_id: '' })
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const saving = ref(false)
const secret = ref<{ name: string; email: string; password: string } | null>(null)
const confirm = ref<string | null>(null)

async function load() {
  state.value = 'loading'
  try {
    ;[members.value, branches.value] = await Promise.all([teamApi.list(), merchantApi.branches()])
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

async function add() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    const m = await teamApi.add({ name: form.name.trim(), email: form.email.trim(), role: form.role, branch_id: form.branch_id || null })
    members.value.push(m)
    secret.value = m.temporary_password ? { name: m.name, email: m.email, password: m.temporary_password } : null
    notify(m.temporary_password ? `${m.name} adicionado(a)` : `${m.name} adicionado(a) — já tinha conta e usa a senha dela`)
    Object.assign(form, { name: '', email: '', role: 'attendant', branch_id: '' })
    adding.value = false
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

async function update(m: StaffMember, body: { role?: string; branch_id?: string | null; active?: boolean }) {
  try {
    const updated = await teamApi.update(m.id, body)
    members.value = members.value.map((x) => (x.id === updated.id ? updated : x))
    notify('Equipe atualizada')
  } catch (e) {
    notify(describeError(e).message, 'error')
    await load()
  }
}

async function twoStep(key: string, run: () => Promise<void>) {
  if (confirm.value !== key) {
    confirm.value = key
    return
  }
  confirm.value = null
  try {
    await run()
  } catch (e) {
    notify(describeError(e).message, 'error')
  }
}

const remove = (m: StaffMember) => twoStep(`rm-${m.id}`, async () => {
  await teamApi.remove(m.id)
  members.value = members.value.filter((x) => x.id !== m.id)
  notify(`${m.name} removido(a) da equipe`)
})

const resetPassword = (m: StaffMember) => twoStep(`pw-${m.id}`, async () => {
  const r = await teamApi.resetPassword(m.id)
  secret.value = { name: m.name, email: r.email, password: r.temporary_password }
})

onMounted(load)
</script>

<template>
  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="font-display text-2xl font-bold">Equipe</h1>
    <SButton v-if="!adding" @click="adding = true">Adicionar pessoa</SButton>
  </div>

  <div v-if="secret" class="mb-6 rounded-(--radius-card) border-2 border-success bg-surface p-4 text-sm" aria-live="polite">
    <p><strong>{{ secret.name }}</strong> entra com <strong>{{ secret.email }}</strong> e esta senha temporária (aparece só agora):</p>
    <code class="mt-2 inline-block rounded-lg bg-canvas px-3 py-2 text-base font-bold tracking-wider">{{ secret.password }}</code>
    <p class="mt-2 text-muted">Peça para trocar em “Minha conta” no primeiro acesso.</p>
    <SButton class="mt-2" variant="ghost" @click="secret = null">Fechar</SButton>
  </div>

  <form v-if="adding" class="mb-6 grid gap-4 rounded-(--radius-card) border border-line bg-surface p-5" novalidate @submit.prevent="add">
    <SAlert v-if="message">{{ message }}</SAlert>
    <div class="grid gap-4 sm:grid-cols-2">
      <STextField v-model="form.name" label="Nome" :error="errors.name?.[0] ?? null" />
      <STextField v-model="form.email" label="E-mail (usado no login)" type="email" :error="errors.email?.[0] ?? null" />
    </div>
    <fieldset>
      <legend class="mb-2 text-sm font-medium">Função</legend>
      <div class="grid gap-2 sm:grid-cols-3">
        <label v-for="r in STAFF_ROLES" :key="r.value" class="cursor-pointer rounded-(--radius-control) border border-line p-3 has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
          <input v-model="form.role" type="radio" :value="r.value" class="sr-only">
          <span class="block font-semibold">{{ r.label }}</span>
          <span class="block text-sm text-muted">{{ r.hint }}</span>
        </label>
      </div>
    </fieldset>
    <label class="grid gap-1.5 text-sm font-medium">Unidade
      <select v-model="form.branch_id" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
        <option value="">Todas as unidades</option>
        <option v-for="b in branches" :key="b.id" :value="b.id">Só {{ b.name }}</option>
      </select>
    </label>
    <div class="flex gap-2">
      <SButton type="submit" :loading="saving">Adicionar</SButton>
      <SButton variant="ghost" @click="adding = false">Cancelar</SButton>
    </div>
  </form>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <ul v-else class="divide-y divide-line rounded-(--radius-card) border border-line bg-surface">
    <li v-for="m in members" :key="m.id" class="grid gap-3 px-4 py-3 md:grid-cols-[1fr_auto] md:items-center">
      <div>
        <p class="font-semibold">{{ m.name }} <span v-if="m.is_self" class="text-sm font-normal text-muted">(você)</span> <span v-if="!m.active" class="rounded-full bg-line px-2 py-0.5 text-xs text-muted">desativado</span></p>
        <p class="text-sm text-muted">{{ m.email }} · último acesso {{ m.last_login_at ? new Date(m.last_login_at).toLocaleDateString('pt-BR') : 'nunca' }}</p>
      </div>
      <div v-if="m.is_owner || m.is_self" class="text-sm text-muted">{{ m.role_name }}<template v-if="m.branch"> · {{ m.branch.name }}</template></div>
      <div v-else class="flex flex-wrap items-center gap-2">
        <select :value="m.role" :aria-label="`Função de ${m.name}`" class="min-h-10 rounded-lg border border-line bg-surface px-2 text-sm" @change="update(m, { role: ($event.target as HTMLSelectElement).value })">
          <option v-for="r in STAFF_ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <select :value="m.branch?.id ?? ''" :aria-label="`Unidade de ${m.name}`" class="min-h-10 rounded-lg border border-line bg-surface px-2 text-sm" @change="update(m, { branch_id: ($event.target as HTMLSelectElement).value || null })">
          <option value="">Todas as unidades</option>
          <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
        <SButton variant="ghost" @click="update(m, { active: !m.active })">{{ m.active ? 'Desativar' : 'Reativar' }}</SButton>
        <SButton variant="ghost" @click="resetPassword(m)">{{ confirm === `pw-${m.id}` ? 'Confirmar nova senha' : 'Nova senha' }}</SButton>
        <SButton variant="ghost" @click="remove(m)">{{ confirm === `rm-${m.id}` ? 'Confirmar remoção' : 'Remover' }}</SButton>
      </div>
    </li>
  </ul>
</template>

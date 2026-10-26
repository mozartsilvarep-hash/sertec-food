<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ApiError, describeError, merchantApi, teamApi, type Branch } from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextField } from '@sertec/ui'
import { notify } from '../notify'
import { can } from '../session'

const branches = ref<Branch[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline' | 'forbidden'>('loading')
const editing = ref<string | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)
const errors = ref<Record<string, string[]>>({})
const form = reactive({ name: '', phone: '', street: '', number: '', neighborhood: '', city: '', state: '', zip: '' })
const manage = can('branches.manage')

async function load() {
  if (!can('branches.view')) {
    state.value = 'forbidden'
    return
  }
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

function edit(b: Branch) {
  editing.value = b.id
  error.value = null
  errors.value = {}
  const a = b.address ?? {}
  Object.assign(form, {
    name: b.name, phone: b.phone ?? '', street: a.street ?? '', number: a.number ?? '',
    neighborhood: a.neighborhood ?? '', city: a.city ?? '', state: a.state ?? '', zip: a.zip ?? '',
  })
}

async function save(b: Branch) {
  saving.value = true
  error.value = null
  errors.value = {}
  try {
    const updated = await teamApi.updateBranch(b.id, {
      name: form.name.trim(),
      phone: form.phone.trim() || null,
      address: {
        street: form.street.trim() || null, number: form.number.trim() || null, neighborhood: form.neighborhood.trim() || null,
        city: form.city.trim() || null, state: form.state.trim().toUpperCase() || null, zip: form.zip.trim() || null,
      },
    })
    branches.value = branches.value.map((x) => (x.id === updated.id ? updated : x))
    editing.value = null
    notify('Unidade atualizada')
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    error.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

const err = (k: string) => errors.value[k]?.[0] ?? null

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
    <li v-for="branch in branches" :key="branch.id" class="px-4 py-3">
      <div class="flex items-center justify-between gap-4">
        <div>
          <p class="font-semibold text-ink">{{ branch.name }}</p>
          <p class="text-sm text-muted">
            {{ branch.phone ?? 'Sem telefone cadastrado' }}<template v-if="branch.address?.street"> · {{ branch.address.street }}, {{ branch.address.number }} — {{ branch.address.neighborhood }}</template>
          </p>
        </div>
        <div class="flex items-center gap-3">
          <span class="text-sm" :class="branch.active ? 'text-success' : 'text-muted'">{{ branch.active ? 'Ativa' : 'Inativa' }}</span>
          <SButton v-if="manage && editing !== branch.id" variant="secondary" @click="edit(branch)">Editar</SButton>
        </div>
      </div>

      <form v-if="editing === branch.id" class="mt-4 grid gap-4 border-t border-line pt-4" novalidate @submit.prevent="save(branch)">
        <SAlert v-if="error">{{ error }}</SAlert>
        <div class="grid gap-4 sm:grid-cols-2">
          <STextField v-model="form.name" label="Nome da unidade" :error="err('name')" />
          <STextField v-model="form.phone" label="Telefone / WhatsApp" type="tel" :error="err('phone')" />
        </div>
        <p class="-mt-2 text-sm text-muted">Com celular, o cliente vê o botão “Falar no WhatsApp” no acompanhamento do pedido.</p>
        <div class="grid gap-4 sm:grid-cols-[1fr_7rem]">
          <STextField v-model="form.street" label="Rua" :error="err('address.street')" />
          <STextField v-model="form.number" label="Número" :error="err('address.number')" />
        </div>
        <div class="grid gap-4 sm:grid-cols-[1fr_1fr_5rem_8rem]">
          <STextField v-model="form.neighborhood" label="Bairro" :error="err('address.neighborhood')" />
          <STextField v-model="form.city" label="Cidade" :error="err('address.city')" />
          <STextField v-model="form.state" label="UF" :error="err('address.state')" />
          <STextField v-model="form.zip" label="CEP" :error="err('address.zip')" />
        </div>
        <div class="flex gap-2">
          <SButton type="submit" :loading="saving">Salvar unidade</SButton>
          <SButton variant="ghost" @click="editing = null">Cancelar</SButton>
        </div>
      </form>
    </li>
  </ul>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { describeError, teamApi } from '@sertec/api-client'
import { SAlert, SButton, STextField } from '@sertec/ui'
import { notify } from '../notify'
import { me } from '../session'

const form = reactive({ current: '', password: '', confirm: '' })
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const saving = ref(false)

async function save() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    await teamApi.changePassword({ current_password: form.current, password: form.password, password_confirmation: form.confirm })
    Object.assign(form, { current: '', password: '', confirm: '' })
    notify('Senha alterada. Outros aparelhos foram desconectados.')
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <h1 class="mb-2 font-display text-2xl font-bold">Minha conta</h1>
  <p class="mb-6 text-muted">{{ me?.user.name }} · {{ me?.user.email }}</p>

  <form class="grid max-w-md gap-4 rounded-(--radius-card) border border-line bg-surface p-5" novalidate @submit.prevent="save">
    <h2 class="font-semibold">Trocar senha</h2>
    <SAlert v-if="message">{{ message }}</SAlert>
    <STextField v-model="form.current" label="Senha atual" type="password" autocomplete="current-password" :error="errors.current_password?.[0] ?? null" />
    <STextField v-model="form.password" label="Nova senha" type="password" autocomplete="new-password" :error="errors.password?.[0] ?? null" />
    <p class="-mt-2 text-sm text-muted">Mínimo de 6 caracteres, com ao menos 1 letra maiúscula e 1 caractere especial (ex.: ! @ # $ %).</p>
    <STextField v-model="form.confirm" label="Repita a nova senha" type="password" autocomplete="new-password" />
    <div><SButton type="submit" :loading="saving">Trocar senha</SButton></div>
  </form>
</template>

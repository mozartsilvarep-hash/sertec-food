<script setup lang="ts">
import { reactive, ref } from 'vue'
import SAlert from './SAlert.vue'
import SButton from './SButton.vue'
import STextField from './STextField.vue'

export interface LoginError {
  message: string
  fields?: Record<string, string[]>
}

const props = defineProps<{
  title: string
  subtitle?: string
  submit: (credentials: { email: string; password: string }) => Promise<void>
  toError: (error: unknown) => LoginError
}>()

const form = reactive({ email: '', password: '' })
const loading = ref(false)
const error = ref<LoginError | null>(null)

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    await props.submit({ email: form.email, password: form.password })
  } catch (e) {
    error.value = props.toError(e)
    form.password = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-(--radius-card) border border-line bg-surface p-6 shadow-sm sm:p-8">
      <header class="mb-6">
        <p class="text-xs font-semibold tracking-widest text-brand-700 uppercase">
          SerTec Food
        </p>
        <h1 class="mt-1 text-2xl font-bold text-ink">
          {{ title }}
        </h1>
        <p v-if="subtitle" class="mt-1 text-sm text-muted">
          {{ subtitle }}
        </p>
      </header>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="onSubmit">
        <SAlert v-if="error && !error.fields">
          {{ error.message }}
        </SAlert>
        <STextField
          v-model="form.email"
          label="E-mail"
          type="email"
          autocomplete="username"
          required
          :error="error?.fields?.email?.[0]"
        />
        <STextField
          v-model="form.password"
          label="Senha"
          type="password"
          autocomplete="current-password"
          required
          :error="error?.fields?.password?.[0]"
        />
        <SButton type="submit" block :loading="loading">
          Entrar
        </SButton>
      </form>
    </div>
  </main>
</template>

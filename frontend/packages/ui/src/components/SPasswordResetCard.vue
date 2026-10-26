<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import SAlert from './SAlert.vue'
import SButton from './SButton.vue'
import STextField from './STextField.vue'

export interface ResetError {
  message: string
  fields?: Record<string, string[]>
}

/**
 * "Esqueci a senha" em duas etapas, nos dois painéis:
 * - mode "request": pede o e-mail e mostra a resposta (sempre a mesma);
 * - mode "reset": recebe token/e-mail do link e cria a senha nova.
 */
const props = defineProps<{
  mode: 'request' | 'reset'
  title: string
  email?: string
  token?: string
  requestLink: (email: string) => Promise<string>
  reset: (body: { email: string; token: string; password: string; password_confirmation: string }) => Promise<string>
  toError: (error: unknown) => ResetError
}>()
const emit = defineEmits<{ done: [message: string] }>()

const form = reactive({ email: props.email ?? '', password: '', confirm: '' })
const loading = ref(false)
const error = ref<ResetError | null>(null)
const sent = ref<string | null>(null)
const invalidLink = computed(() => props.mode === 'reset' && (!props.token || !props.email))

async function onSubmit() {
  loading.value = true
  error.value = null
  try {
    if (props.mode === 'request') {
      sent.value = await props.requestLink(form.email.trim())
    } else {
      emit('done', await props.reset({ email: props.email ?? '', token: props.token ?? '', password: form.password, password_confirmation: form.confirm }))
    }
  } catch (e) {
    error.value = props.toError(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <main class="flex min-h-dvh items-center justify-center p-4">
    <div class="w-full max-w-sm rounded-(--radius-card) border border-line bg-surface p-6 shadow-sm sm:p-8">
      <header class="mb-6">
        <p class="font-display text-sm font-semibold text-brand-700">SerTec Food</p>
        <h1 class="mt-1 font-display text-2xl font-bold text-ink">{{ mode === 'request' ? 'Esqueci minha senha' : 'Criar senha nova' }}</h1>
        <p class="mt-1 text-sm text-muted">{{ title }}</p>
      </header>

      <p v-if="sent" class="rounded-(--radius-control) bg-brand-50 p-3 text-sm" role="status">{{ sent }}</p>
      <SAlert v-else-if="invalidLink">Link incompleto. Abra o link do e-mail de novo ou peça outro.</SAlert>
      <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="onSubmit">
        <SAlert v-if="error && !error.fields">{{ error.message }}</SAlert>
        <template v-if="mode === 'request'">
          <p class="text-sm text-muted">Informe o e-mail que você usa para entrar. Enviaremos um link para criar uma senha nova.</p>
          <STextField v-model="form.email" label="E-mail" type="email" autocomplete="username" required :error="error?.fields?.email?.[0]" />
          <SButton type="submit" block :loading="loading">Enviar link</SButton>
        </template>
        <template v-else>
          <p class="text-sm text-muted">Conta: <strong class="text-ink">{{ email }}</strong></p>
          <STextField v-model="form.password" label="Nova senha" type="password" autocomplete="new-password" required :error="error?.fields?.password?.[0]" />
          <p class="-mt-2 text-sm text-muted">Mínimo de 6 caracteres, com ao menos 1 letra maiúscula e 1 caractere especial (ex.: ! @ # $ %).</p>
          <STextField v-model="form.confirm" label="Repita a nova senha" type="password" autocomplete="new-password" required />
          <SButton type="submit" block :loading="loading">Salvar senha nova</SButton>
        </template>
      </form>
      <div class="mt-4 text-center text-sm"><slot name="footer" /></div>
    </div>
  </main>
</template>

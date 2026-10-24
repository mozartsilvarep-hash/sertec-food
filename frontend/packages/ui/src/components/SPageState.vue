<script setup lang="ts">
/**
 * Estados de página exigidos pelo DESIGN.md: carregando, vazio, erro,
 * offline e indisponível — com mensagem clara e ação de nova tentativa.
 */
defineProps<{
  state: 'loading' | 'empty' | 'error' | 'offline' | 'unavailable'
  title?: string
  message?: string
}>()

defineEmits<{ retry: [] }>()

const defaults = {
  loading: { title: 'Carregando…', message: '' },
  empty: { title: 'Nada por aqui ainda', message: '' },
  error: { title: 'Algo deu errado', message: 'Tente novamente em instantes.' },
  offline: { title: 'Você está sem internet', message: 'Verifique sua conexão e tente de novo.' },
  unavailable: { title: 'Indisponível no momento', message: 'Este estabelecimento não está recebendo acessos agora.' },
} as const
</script>

<template>
  <section
    class="flex flex-col items-center justify-center gap-3 px-6 py-16 text-center"
    :aria-busy="state === 'loading' || undefined"
    aria-live="polite"
  >
    <span
      v-if="state === 'loading'"
      class="size-8 animate-spin rounded-full border-4 border-brand-100 border-t-brand-600"
      aria-hidden="true"
    />
    <h2 class="text-lg font-semibold text-ink">
      {{ title ?? defaults[state].title }}
    </h2>
    <p v-if="message ?? defaults[state].message" class="max-w-sm text-sm text-muted">
      {{ message ?? defaults[state].message }}
    </p>
    <button
      v-if="state === 'error' || state === 'offline'"
      type="button"
      class="mt-2 text-sm font-semibold text-brand-700 underline underline-offset-4"
      @click="$emit('retry')"
    >
      Tentar novamente
    </button>
  </section>
</template>

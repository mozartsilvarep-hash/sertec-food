<script setup lang="ts">
/**
 * Página não encontrada (rota desconhecida nas SPAs). As ações vêm pelo slot
 * (cada app usa o próprio RouterLink).
 */
withDefaults(defineProps<{ title?: string; message?: string }>(), {
  title: 'Página não encontrada',
  message: 'O endereço pode ter sido digitado errado ou a página não existe mais.',
})
</script>

<template>
  <section class="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center gap-3 px-6 py-16 text-center" aria-labelledby="nao-encontrada">
    <svg class="size-14 text-brand-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5M8.5 11h5" />
    </svg>
    <p class="text-xs font-semibold tracking-[0.14em] text-brand-700 uppercase">Erro 404</p>
    <h1 id="nao-encontrada" class="font-display text-2xl font-bold text-balance text-ink">{{ title }}</h1>
    <p class="text-muted text-pretty">{{ message }}</p>
    <div class="mt-3 flex flex-wrap justify-center gap-2">
      <slot />
    </div>
  </section>
</template>

<script setup lang="ts">
import { SNotFound } from '@sertec/ui'
</script>

<template>
  <SNotFound>
    <RouterLink
      to="/"
      class="inline-flex min-h-11 items-center justify-center rounded-(--radius-control) bg-brand-600 px-5 text-sm font-semibold text-brand-contrast hover:bg-brand-700"
      title="Ir para o início do painel"
    >
      Ir para o início do painel
    </RouterLink>
  </SNotFound>
</template>

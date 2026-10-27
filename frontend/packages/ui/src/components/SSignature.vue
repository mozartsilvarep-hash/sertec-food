<script setup lang="ts">
/** Assinatura da plataforma: "Feito por SerTec Solution" com link para o site. */
withDefaults(defineProps<{ product?: string }>(), { product: 'SerTec Food' })
</script>

<template>
  <p class="text-xs text-muted">
    {{ product }} · feito por
    <a href="https://sertecsolution.com.br" target="_blank" rel="noopener" class="font-semibold text-ink underline-offset-2 hover:underline">SerTec Solution</a>
  </p>
</template>

<script setup lang="ts">
defineProps<{ title: string; back?: string }>()
</script>

<template>
  <header class="sticky top-0 z-10 border-b border-line bg-surface/95 backdrop-blur">
    <div class="mx-auto flex max-w-2xl items-center gap-2 px-2 py-2">
      <RouterLink :to="back ?? '/'" class="grid size-11 place-items-center rounded-full text-2xl text-ink hover:bg-canvas" aria-label="Voltar">
        ‹
      </RouterLink>
      <h1 class="font-display text-lg font-bold">{{ title }}</h1>
    </div>
  </header>
</template>

<script setup lang="ts">
defineProps<{ tone?: 'danger' | 'info' }>()
</script>

<template>
  <div
    role="alert"
    class="rounded-(--radius-control) px-4 py-3 text-sm"
    :class="tone === 'info' ? 'bg-brand-50 text-brand-700' : 'bg-danger-soft text-danger'"
  >
    <slot />
  </div>
</template>

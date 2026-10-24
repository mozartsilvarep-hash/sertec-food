<script setup lang="ts">
withDefaults(
  defineProps<{
    type?: 'button' | 'submit'
    variant?: 'primary' | 'secondary' | 'ghost'
    loading?: boolean
    disabled?: boolean
    block?: boolean
  }>(),
  { type: 'button', variant: 'primary', loading: false, disabled: false, block: false },
)
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading || undefined"
    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-(--radius-control) px-4 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-60"
    :class="[
      block && 'w-full',
      variant === 'primary' && 'bg-brand-600 text-brand-contrast hover:bg-brand-700',
      variant === 'secondary' && 'border border-line bg-surface text-ink hover:bg-canvas',
      variant === 'ghost' && 'text-muted hover:bg-canvas hover:text-ink',
    ]"
  >
    <span
      v-if="loading"
      class="size-4 animate-spin rounded-full border-2 border-current border-r-transparent"
      aria-hidden="true"
    />
    <slot />
  </button>
</template>

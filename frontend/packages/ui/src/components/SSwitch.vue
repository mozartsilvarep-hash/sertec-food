<script setup lang="ts">
defineProps<{ label: string; disabled?: boolean; hideLabel?: boolean }>()
const model = defineModel<boolean>({ required: true })
</script>

<template>
  <label class="inline-flex cursor-pointer items-center gap-3 select-none" :class="disabled && 'cursor-not-allowed opacity-60'">
    <span
      class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
      :class="model ? 'bg-brand-600' : 'bg-line'"
    >
      <input v-model="model" type="checkbox" role="switch" class="peer sr-only" :disabled="disabled" :aria-label="hideLabel ? label : undefined">
      <span
        class="inline-block size-5 rounded-full bg-white shadow transition-transform peer-focus-visible:outline-3 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-500"
        :class="model ? 'translate-x-5.5' : 'translate-x-0.5'"
      />
    </span>
    <span v-if="!hideLabel" class="text-sm text-ink">{{ label }}</span>
  </label>
</template>

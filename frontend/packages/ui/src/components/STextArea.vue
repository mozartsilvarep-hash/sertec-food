<script setup lang="ts">
import { useId } from 'vue'

defineProps<{ label: string; rows?: number; maxlength?: number; error?: string | null; hint?: string }>()
const model = defineModel<string>({ required: true })
const id = useId()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-medium text-ink">{{ label }}</label>
    <textarea
      :id="id"
      v-model="model"
      :rows="rows ?? 3"
      :maxlength="maxlength"
      :aria-invalid="error ? true : undefined"
      class="rounded-(--radius-control) border bg-surface px-3 py-2 text-base text-ink"
      :class="error ? 'border-danger' : 'border-line'"
    />
    <p v-if="error" class="text-sm text-danger">{{ error }}</p>
    <p v-else-if="hint" class="text-sm text-muted">{{ hint }}</p>
  </div>
</template>

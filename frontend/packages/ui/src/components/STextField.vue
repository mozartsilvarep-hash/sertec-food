<script setup lang="ts">
import { useId } from 'vue'

defineProps<{
  label: string
  type?: 'text' | 'email' | 'password' | 'tel'
  autocomplete?: string
  required?: boolean
  error?: string | null
}>()

const model = defineModel<string>({ required: true })
const id = useId()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-medium text-ink">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      :type="type ?? 'text'"
      :autocomplete="autocomplete"
      :required="required"
      :aria-invalid="error ? true : undefined"
      :aria-describedby="error ? `${id}-error` : undefined"
      class="min-h-11 rounded-(--radius-control) border bg-surface px-3 text-base text-ink placeholder:text-muted"
      :class="error ? 'border-danger' : 'border-line'"
    >
    <p v-if="error" :id="`${id}-error`" class="text-sm text-danger">
      {{ error }}
    </p>
  </div>
</template>

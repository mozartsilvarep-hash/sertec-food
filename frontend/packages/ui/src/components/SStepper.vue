<script setup lang="ts">
const props = defineProps<{ label: string; min?: number; max: number }>()
const model = defineModel<number>({ required: true })

function change(delta: number) {
  const next = Math.min(props.max, Math.max(props.min ?? 0, model.value + delta))
  model.value = next
}
</script>

<template>
  <div class="inline-flex items-center rounded-full border border-line" role="group" :aria-label="label">
    <button type="button" class="grid size-9 place-items-center rounded-full text-lg text-ink disabled:opacity-40" :disabled="model <= (min ?? 0)" :aria-label="`Remover ${label}`" @click="change(-1)">
      −
    </button>
    <output class="w-6 text-center text-sm font-semibold tabular-nums" aria-live="polite">{{ model }}</output>
    <button type="button" class="grid size-9 place-items-center rounded-full text-lg text-ink disabled:opacity-40" :disabled="model >= max" :aria-label="`Adicionar ${label}`" @click="change(1)">
      +
    </button>
  </div>
</template>

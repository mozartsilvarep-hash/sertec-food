<script setup lang="ts">
import type { Branch } from '@sertec/api-client'

/** allowAll: primeira opção "Todas as unidades" (valor vazio). */
defineProps<{ branches: Branch[]; allowAll?: boolean }>()
const model = defineModel<string>({ required: true })
</script>

<template>
  <label v-if="branches.length > 1" class="mb-6 grid max-w-xs gap-1.5 text-sm font-medium">
    Unidade
    <select v-model="model" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
      <option v-if="allowAll" value="">Todas as unidades</option>
      <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
    </select>
  </label>
</template>

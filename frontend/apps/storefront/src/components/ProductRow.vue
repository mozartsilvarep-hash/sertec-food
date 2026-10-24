<script setup lang="ts">
import { formatMoney, type ProductSummary } from '@sertec/api-client'

defineProps<{ product: ProductSummary }>()
defineEmits<{ open: [] }>()
</script>

<template>
  <li>
    <button
      type="button"
      class="flex w-full items-start gap-4 py-4 text-left disabled:cursor-not-allowed"
      :disabled="!product.available"
      @click="$emit('open')"
    >
      <span class="min-w-0 flex-1">
        <span class="block font-semibold text-ink" :class="!product.available && 'text-muted'">{{ product.name }}</span>
        <span v-if="product.description" class="mt-1 line-clamp-2 block text-sm text-muted">{{ product.description }}</span>
        <span class="mt-2 block text-sm">
          <template v-if="!product.available">
            <span class="font-semibold text-muted">Esgotado hoje</span>
          </template>
          <template v-else-if="product.price_from">
            <span v-if="product.has_options" class="text-muted">a partir de </span>
            <span class="font-semibold text-ink tabular-nums">{{ formatMoney(product.price_from) }}</span>
          </template>
        </span>
      </span>
      <img
        v-if="product.image_url"
        :src="product.image_url"
        :alt="''"
        loading="lazy"
        decoding="async"
        width="96"
        height="96"
        class="size-24 shrink-0 rounded-xl object-cover"
        :class="!product.available && 'grayscale'"
      >
    </button>
  </li>
</template>

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
            <span class="font-semibold text-muted">{{ product.availability ?? 'Esgotado hoje' }}</span>
          </template>
          <template v-else-if="product.price_from">
            <span v-if="product.has_options" class="text-muted">a partir de </span>
            <template v-if="product.promo_price_from">
              <span class="mr-1 text-muted tabular-nums line-through">{{ formatMoney(product.price_from) }}</span>
              <span class="font-semibold text-success tabular-nums">{{ formatMoney(product.promo_price_from) }}</span>
            </template>
            <template v-else>
              <span v-if="product.compare_at && Number(product.compare_at) > Number(product.price_from)" class="mr-1 text-muted tabular-nums line-through">{{ formatMoney(product.compare_at) }}</span>
              <span class="font-semibold text-ink tabular-nums">{{ formatMoney(product.price_from) }}</span>
            </template>
          </template>
          <span v-if="product.available && product.promotion" class="ml-2 inline-block rounded-full bg-success px-2 py-0.5 align-middle text-xs font-bold text-white">{{ product.promotion.label }}</span>
          <span v-if="product.kind === 'combo'" class="ml-2 inline-block rounded-full bg-brand-600 px-2 py-0.5 align-middle text-xs font-bold text-brand-contrast">Combo</span>
        </span>
        <span v-if="product.available && product.availability" class="mt-1 block text-xs text-muted">{{ product.availability }}</span>
      </span>
      <img
        v-if="product.image_url"
        :src="product.image_url"
        :alt="''"
        loading="lazy"
        decoding="async"
        width="96"
        height="96"
        class="size-24 shrink-0 rounded-2xl object-cover sm:size-28"
        :class="!product.available && 'grayscale'"
      >
    </button>
  </li>
</template>

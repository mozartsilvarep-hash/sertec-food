<script setup lang="ts">
import { formatMoney, type ProductSummary } from '@sertec/api-client'

defineProps<{ products: ProductSummary[] }>()
defineEmits<{ open: [product: ProductSummary] }>()

function tag(p: ProductSummary): string | null {
  if (p.promotion) return p.promotion.label
  if (p.kind === 'combo') return 'Combo'
  return null
}
</script>

<template>
  <section aria-labelledby="destaques" class="pt-6">
    <h2 id="destaques" class="font-display text-2xl font-bold tracking-tight">Destaques</h2>
    <ul class="relative -mx-4 mt-3 flex snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 sm:scroll-px-6 [scrollbar-width:none] sm:-mx-6 sm:px-6">
      <li v-for="p in products" :key="p.id" class="w-[min(15rem,68vw)] shrink-0 snap-start">
        <button type="button" class="group block w-full text-left" @click="$emit('open', p)">
          <span class="relative block overflow-hidden rounded-2xl bg-canvas">
            <img :src="(p.thumb_url ?? p.image_url)!" alt="" loading="lazy" decoding="async" class="aspect-[4/3] w-full object-cover transition-transform duration-300 group-hover:scale-[1.03]">
            <span v-if="tag(p)" class="absolute top-2.5 left-2.5 rounded-full bg-brand-600 px-2.5 py-1 text-xs font-bold text-brand-contrast">{{ tag(p) }}</span>
          </span>
          <span class="mt-2 block truncate font-semibold">{{ p.name }}</span>
          <span class="block text-sm tabular-nums">
            <template v-if="p.promo_price_from">
              <span class="mr-1 text-muted line-through">{{ formatMoney(p.price_from!) }}</span>
              <span class="font-semibold text-success">{{ formatMoney(p.promo_price_from) }}</span>
            </template>
            <template v-else-if="p.price_from">
              <span v-if="p.compare_at && Number(p.compare_at) > Number(p.price_from)" class="mr-1 text-muted line-through">{{ formatMoney(p.compare_at) }}</span>
              <span class="font-semibold">{{ formatMoney(p.price_from) }}</span>
            </template>
          </span>
        </button>
      </li>
    </ul>
  </section>
</template>

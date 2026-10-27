<script setup lang="ts">
import { computed } from 'vue'
import { openingLabel, type BranchStatus, type StorefrontInfo } from '@sertec/api-client'
import { initials, instagramUrl, mapsUrl, storeWhatsapp } from '../identity'

const props = defineProps<{ store: StorefrontInfo; branch: BranchStatus | null }>()
const emit = defineEmits<{ changeBranch: [id: string] }>()

const status = computed(() => (props.branch ? openingLabel(props.branch, props.store.timezone) : null))
const whatsapp = computed(() => storeWhatsapp(props.store, props.branch))
const instagram = computed(() => instagramUrl(props.store.instagram))
const maps = computed(() => mapsUrl(props.branch?.address, props.store.name))
</script>

<template>
  <header>
    <!-- Faixa: capa da loja ou a cor da marca com uma trama sutil. -->
    <div class="relative mx-auto max-w-3xl">
      <img
        v-if="store.cover_url"
        :src="store.cover_url"
        alt=""
        class="aspect-[2.5/1] w-full object-cover sm:rounded-b-[1.75rem]"
        fetchpriority="high"
        decoding="async"
      >
      <div
        v-else
        class="h-28 w-full bg-brand-600 sm:h-36 sm:rounded-b-[1.75rem]"
        style="background-image: radial-gradient(circle at 1px 1px, rgb(255 255 255 / 0.14) 1px, transparent 0); background-size: 14px 14px"
        aria-hidden="true"
      />
    </div>

    <div class="mx-auto max-w-2xl px-4 pb-6 sm:px-6">
      <div class="-mt-11 flex items-end gap-4 sm:-mt-14">
        <img
          v-if="store.logo_url"
          :src="store.logo_url"
          :alt="`Logo ${store.name}`"
          width="112"
          height="112"
          class="relative size-22 shrink-0 rounded-[1.4rem] bg-surface object-cover shadow-[0_8px_24px_-12px_rgb(28_25_23/0.45)] ring-4 ring-canvas sm:size-28"
        >
        <span
          v-else
          class="relative grid size-22 shrink-0 place-items-center rounded-[1.4rem] bg-brand-700 font-display text-3xl font-extrabold text-brand-contrast ring-4 ring-canvas sm:size-28 sm:text-4xl"
          aria-hidden="true"
        >{{ initials(store.name) }}</span>
      </div>

      <h1 class="mt-4 font-display text-[clamp(2rem,8vw,3.25rem)] leading-[0.98] font-extrabold tracking-[-0.025em] text-balance">
        {{ store.name }}
      </h1>
      <p v-if="store.description" class="mt-2.5 max-w-[60ch] text-base/relaxed text-muted">
        {{ store.description }}
      </p>

      <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2.5">
        <p v-if="status" class="inline-flex items-center gap-2 text-sm font-semibold" :class="branch?.open ? 'text-success' : 'text-muted'">
          <span
            class="size-2.5 rounded-full"
            :class="branch?.open ? 'bg-success shadow-[0_0_0_4px_rgb(21_128_61/0.15)]' : 'bg-muted/50'"
            aria-hidden="true"
          />
          {{ status }}
        </p>

        <label v-if="store.branches.length > 1" class="text-sm">
          <span class="sr-only">Unidade</span>
          <select
            class="rounded-full border border-line bg-surface py-1.5 pr-8 pl-3 font-semibold"
            :value="branch?.id"
            @change="emit('changeBranch', ($event.target as HTMLSelectElement).value)"
          >
            <option v-for="b in store.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </label>
        <p v-else-if="branch && branch.name !== store.name" class="text-sm text-muted">{{ branch.name }}</p>
      </div>

      <ul v-if="whatsapp || instagram || maps" class="mt-5 flex flex-wrap gap-2" aria-label="Contato">
        <li v-if="whatsapp">
          <a :href="whatsapp" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center gap-2 rounded-full bg-brand-50 px-4 text-sm font-semibold text-brand-700 hover:bg-brand-100">
            <svg viewBox="0 0 24 24" class="size-4" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.2-.2-.4-.3Z" /></svg>
            WhatsApp
          </a>
        </li>
        <li v-if="instagram">
          <a :href="instagram" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center gap-2 rounded-full bg-brand-50 px-4 text-sm font-semibold text-brand-700 hover:bg-brand-100">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5" /><circle cx="12" cy="12" r="4" /><circle cx="17.5" cy="6.5" r="0.6" fill="currentColor" /></svg>
            @{{ store.instagram }}
          </a>
        </li>
        <li v-if="maps">
          <a :href="maps" target="_blank" rel="noopener" class="inline-flex min-h-10 items-center gap-2 rounded-full bg-brand-50 px-4 text-sm font-semibold text-brand-700 hover:bg-brand-100">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5a7 7 0 1 1 14 0C19 14.8 12 21 12 21Z" /><circle cx="12" cy="9.5" r="2.5" /></svg>
            Como chegar
          </a>
        </li>
      </ul>
    </div>
  </header>
</template>

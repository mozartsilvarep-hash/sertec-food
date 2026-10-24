<script setup lang="ts">
import { computed } from 'vue'
import { openingLabel, type BranchStatus, type StorefrontInfo } from '@sertec/api-client'

const props = defineProps<{ store: StorefrontInfo; branch: BranchStatus | null }>()
const emit = defineEmits<{ changeBranch: [id: string] }>()

const status = computed(() => (props.branch ? openingLabel(props.branch, props.store.timezone) : null))
</script>

<template>
  <header class="bg-brand-600 text-brand-contrast">
    <div class="mx-auto max-w-2xl px-4 pt-12 pb-7 sm:px-6 sm:pt-16">
      <h1 class="font-display text-[clamp(2.25rem,9vw,3.75rem)] leading-[0.95] font-extrabold tracking-[-0.02em] text-balance">
        {{ store.name }}
      </h1>
      <p v-if="store.description" class="mt-3 max-w-[60ch] text-base/relaxed text-white/85">
        {{ store.description }}
      </p>

      <div class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-3">
        <p v-if="status" class="inline-flex items-center gap-2 text-sm font-semibold">
          <span
            class="size-2.5 rounded-full"
            :class="branch?.open ? 'bg-emerald-300 shadow-[0_0_0_4px_rgb(110_231_183/0.25)]' : 'bg-white/50'"
            aria-hidden="true"
          />
          {{ status }}
        </p>

        <label v-if="store.branches.length > 1" class="text-sm">
          <span class="sr-only">Unidade</span>
          <select
            class="rounded-full border border-white/40 bg-white/10 py-1.5 pr-8 pl-3 font-semibold text-white"
            :value="branch?.id"
            @change="emit('changeBranch', ($event.target as HTMLSelectElement).value)"
          >
            <option v-for="b in store.branches" :key="b.id" :value="b.id" class="text-ink">
              {{ b.name }}
            </option>
          </select>
        </label>
        <p v-else-if="branch" class="text-sm text-white/80">{{ branch.name }}</p>
      </div>
    </div>
  </header>
</template>

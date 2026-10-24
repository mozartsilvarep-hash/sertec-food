<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { merchantApi } from '@sertec/api-client'
import { SButton } from '@sertec/ui'
import { notice } from '../notify'
import { can, me } from '../session'

const router = useRouter()

const links = computed(() => [
  { to: '/', label: 'Unidades', show: can('branches.view') },
  { to: '/cardapio', label: 'Cardápio', show: can('catalog.view') },
  { to: '/opcoes', label: 'Opções e sabores', show: can('catalog.view') },
  { to: '/esgotados', label: 'Esgotados', show: can('catalog.availability') },
  { to: '/horarios', label: 'Horários', show: can('branches.view') },
  { to: '/marca', label: 'Marca', show: can('settings.view') },
].filter((l) => l.show))

async function logout() {
  await merchantApi.logout().catch(() => undefined)
  me.value = null
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-dvh md:grid md:grid-cols-[15rem_1fr]">
    <aside class="border-b border-line bg-surface md:min-h-dvh md:border-r md:border-b-0">
      <div class="flex items-center justify-between gap-3 px-4 py-4 md:block md:px-5 md:py-6">
        <div>
          <p class="font-display text-lg leading-tight font-bold text-ink">{{ me?.tenant.name }}</p>
          <p class="text-sm text-muted">{{ me?.user.name }}</p>
        </div>
        <SButton variant="ghost" class="md:mt-4 md:-ml-4" @click="logout">
          Sair
        </SButton>
      </div>
      <nav aria-label="Seções do painel">
        <ul class="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-col md:px-3">
          <li v-for="link in links" :key="link.to">
            <RouterLink
              :to="link.to"
              class="block rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap text-muted hover:bg-canvas hover:text-ink"
              active-class=""
              exact-active-class="bg-brand-50 text-brand-700"
            >
              {{ link.label }}
            </RouterLink>
          </li>
        </ul>
      </nav>
    </aside>

    <main class="min-w-0 px-4 py-6 md:px-8 md:py-8">
      <RouterView />
    </main>

    <div
      v-if="notice"
      role="status"
      class="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-sm rounded-xl px-4 py-3 text-sm font-medium shadow-lg md:right-6 md:left-auto"
      :class="notice.tone === 'ok' ? 'bg-ink text-white' : 'bg-danger text-white'"
    >
      {{ notice.text }}
    </div>
  </div>
</template>

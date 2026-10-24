<script setup lang="ts">
import { useRouter } from 'vue-router'
import { adminApi } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import { me } from '../session'

const router = useRouter()

async function logout() {
  await adminApi.logout().catch(() => undefined)
  me.value = null
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="min-h-dvh">
    <header class="border-b border-line bg-surface">
      <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
        <div>
          <p class="text-xs font-semibold tracking-widest text-brand-700 uppercase">
            SerTec Food
          </p>
          <h1 class="text-lg font-bold text-ink">
            Painel da plataforma
          </h1>
        </div>
        <div class="flex items-center gap-3">
          <span class="hidden text-sm text-muted sm:inline">{{ me?.user.name }} · {{ me?.user.platform_role }}</span>
          <SButton variant="secondary" @click="logout">
            Sair
          </SButton>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-5xl px-4 py-6">
      <SPageState
        state="empty"
        title="Estabelecimentos"
        message="A gestão de estabelecimentos pelo painel chega numa próxima sprint. Por enquanto use: php artisan tenant:create"
      />
    </main>
  </div>
</template>

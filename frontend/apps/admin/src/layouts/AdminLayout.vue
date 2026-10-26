<script setup lang="ts">
import { useRouter } from 'vue-router'
import { adminApi } from '@sertec/api-client'
import { SButton } from '@sertec/ui'
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
      <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-3">
        <div class="flex items-center gap-6">
          <div>
            <p class="text-xs font-semibold tracking-widest text-brand-700 uppercase">SerTec Food</p>
            <p class="text-lg font-bold text-ink">Painel da plataforma</p>
          </div>
          <nav aria-label="Seções" class="flex gap-1">
            <RouterLink to="/" class="rounded-lg px-3 py-2 text-sm font-medium text-muted hover:bg-canvas hover:text-ink" exact-active-class="bg-brand-50 text-brand-700" active-class="">Estabelecimentos</RouterLink>
            <RouterLink to="/auditoria" class="rounded-lg px-3 py-2 text-sm font-medium text-muted hover:bg-canvas hover:text-ink" active-class="bg-brand-50 text-brand-700">Auditoria</RouterLink>
            <RouterLink to="/sistema" class="rounded-lg px-3 py-2 text-sm font-medium text-muted hover:bg-canvas hover:text-ink" active-class="bg-brand-50 text-brand-700">Sistema</RouterLink>
          </nav>
        </div>
        <div class="flex items-center gap-3">
          <span class="hidden text-sm text-muted sm:inline">{{ me?.user.name }} · {{ me?.user.platform_role === 'superadmin' ? 'Administrador' : 'Suporte' }}</span>
          <SButton variant="secondary" @click="logout">Sair</SButton>
        </div>
      </div>
    </header>
    <main class="mx-auto max-w-6xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, merchantApi, type Branch } from '@sertec/api-client'
import { SButton, SPageState } from '@sertec/ui'
import { can, me } from '../session'

const router = useRouter()
const branches = ref<Branch[]>([])
const state = ref<'loading' | 'ready' | 'error' | 'offline' | 'forbidden'>('loading')

async function loadBranches() {
  if (!can('branches.view')) {
    state.value = 'forbidden'
    return
  }
  state.value = 'loading'
  try {
    branches.value = await merchantApi.branches()
    state.value = 'ready'
  } catch (error) {
    state.value = error instanceof ApiError && error.code === 'network_error' ? 'offline' : 'error'
  }
}

async function logout() {
  await merchantApi.logout().catch(() => undefined)
  me.value = null
  await router.replace({ name: 'login' })
}

onMounted(loadBranches)
</script>

<template>
  <div class="min-h-dvh">
    <header class="border-b border-line bg-surface">
      <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
        <div>
          <p class="text-xs font-semibold tracking-widest text-brand-700 uppercase">
            Painel
          </p>
          <h1 class="text-lg font-bold text-ink">
            {{ me?.tenant.name }}
          </h1>
        </div>
        <div class="flex items-center gap-3">
          <span class="hidden text-sm text-muted sm:inline">{{ me?.user.name }}</span>
          <SButton variant="secondary" @click="logout">
            Sair
          </SButton>
        </div>
      </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-6">
      <h2 class="mb-4 text-base font-semibold text-ink">
        Unidades
      </h2>

      <SPageState v-if="state === 'loading'" state="loading" />
      <SPageState v-else-if="state === 'offline'" state="offline" @retry="loadBranches" />
      <SPageState v-else-if="state === 'error'" state="error" @retry="loadBranches" />
      <SPageState
        v-else-if="state === 'forbidden'"
        state="empty"
        title="Sem acesso às unidades"
        message="Peça ao proprietário para ajustar suas permissões."
      />
      <SPageState v-else-if="branches.length === 0" state="empty" />
      <ul v-else class="grid gap-3 sm:grid-cols-2">
        <li
          v-for="branch in branches"
          :key="branch.id"
          class="rounded-(--radius-card) border border-line bg-surface p-4"
        >
          <p class="font-semibold text-ink">
            {{ branch.name }}
          </p>
          <p class="text-sm text-muted">
            {{ branch.phone ?? 'Sem telefone' }} · {{ branch.active ? 'Ativa' : 'Inativa' }}
          </p>
        </li>
      </ul>
    </main>
  </div>
</template>

<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { adminApi, describeError, type Credentials } from '@sertec/api-client'
import { SLoginCard } from '@sertec/ui'
import { me } from '../session'

const router = useRouter()
// Aviso vindo de "Criar senha nova".
const aviso = useRoute().query.aviso
const notice = typeof aviso === 'string' ? aviso : null

async function submit(credentials: Credentials) {
  me.value = await adminApi.login(credentials)
  await router.replace({ name: 'home' })
}
</script>

<template>
  <SLoginCard
    title="Painel SerTec"
    subtitle="Acesso restrito à equipe SerTec Solution."
    :submit="submit"
    :to-error="describeError"
  >
    <template #footer>
      <p v-if="notice" class="mb-3 rounded-(--radius-control) bg-brand-50 p-2 text-ink" role="status">{{ notice }}</p>
      <RouterLink to="/esqueci-senha" class="font-medium text-brand-700 hover:underline">Esqueci minha senha</RouterLink>
    </template>
  </SLoginCard>
</template>

<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { describeError, adminApi } from '@sertec/api-client'
import { SPasswordResetCard } from '@sertec/ui'

// Link do e-mail: ?token=…&email=…
const route = useRoute()
const router = useRouter()
const token = typeof route.query.token === 'string' ? route.query.token : ''
const email = typeof route.query.email === 'string' ? route.query.email : ''

async function done(message: string) {
  await router.replace({ name: 'login', query: { aviso: message } })
}
</script>

<template>
  <SPasswordResetCard mode="reset" title="Painel SerTec" :token="token" :email="email" :request-link="adminApi.forgotPassword" :reset="adminApi.resetPassword" :to-error="describeError" @done="done">
    <template #footer>
      <RouterLink to="/esqueci-senha" class="font-medium text-brand-700 hover:underline">Pedir outro link</RouterLink>
    </template>
  </SPasswordResetCard>
</template>

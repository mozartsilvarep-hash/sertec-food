<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { describeError, merchantApi } from '@sertec/api-client'
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
  <SPasswordResetCard mode="reset" title="Painel do restaurante" :token="token" :email="email" :request-link="merchantApi.forgotPassword" :reset="merchantApi.resetPassword" :to-error="describeError" @done="done">
    <template #footer>
      <RouterLink to="/esqueci-senha" class="font-medium text-brand-700 hover:underline">Pedir outro link</RouterLink>
    </template>
  </SPasswordResetCard>
</template>

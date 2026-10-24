<script setup lang="ts">
import { useRouter } from 'vue-router'
import { adminApi, describeError, type Credentials } from '@sertec/api-client'
import { SLoginCard } from '@sertec/ui'
import { me } from '../session'

const router = useRouter()

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
  />
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { describeError, merchantApi, type Credentials } from '@sertec/api-client'
import { SLoginCard } from '@sertec/ui'
import { me } from '../session'

const router = useRouter()

async function submit(credentials: Credentials) {
  me.value = await merchantApi.login(credentials)
  await router.replace({ name: 'home' })
}
</script>

<template>
  <SLoginCard
    title="Painel do restaurante"
    subtitle="Entre com o e-mail cadastrado pelo estabelecimento."
    :submit="submit"
    :to-error="describeError"
  />
</template>

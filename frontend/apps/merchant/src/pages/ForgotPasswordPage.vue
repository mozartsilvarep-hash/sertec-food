<script setup lang="ts">
import { describeError, merchantApi } from '@sertec/api-client'
import { SPasswordResetCard } from '@sertec/ui'
</script>

<template>
  <SPasswordResetCard mode="request" title="Painel do restaurante" :request-link="merchantApi.forgotPassword" :reset="merchantApi.resetPassword" :to-error="describeError">
    <template #footer>
      <RouterLink to="/login" class="font-medium text-brand-700 hover:underline">Voltar para o login</RouterLink>
    </template>
  </SPasswordResetCard>
</template>

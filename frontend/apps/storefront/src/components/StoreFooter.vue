<script setup lang="ts">
import { computed } from 'vue'
import type { BranchStatus, StorefrontInfo } from '@sertec/api-client'
import { addressLine } from '../identity'

const props = defineProps<{ store: StorefrontInfo; branch: BranchStatus | null }>()
const address = computed(() => addressLine(props.branch?.address))
</script>

<template>
  <footer class="mt-14 border-t border-line pt-8 pb-28 text-sm text-muted">
    <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Links do rodapé">
      <RouterLink to="/acompanhar" class="font-semibold text-brand-700 hover:underline">Acompanhar pedido</RouterLink>
      <RouterLink to="/privacidade" class="hover:text-ink hover:underline">Privacidade</RouterLink>
    </nav>
    <!-- Identificação do fornecedor (Decreto 7.962/2013). -->
    <address class="mt-5 grid gap-0.5 not-italic">
      <strong class="font-semibold text-ink">{{ store.legal_name ?? store.name }}</strong>
      <span v-if="store.cnpj">CNPJ {{ store.cnpj }}</span>
      <span v-if="address">{{ address }}</span>
      <span v-if="branch?.phone">Telefone {{ branch.phone }}</span>
    </address>
    <p class="mt-5 text-xs">Cardápio digital SerTec Food</p>
  </footer>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, tableApi } from '@sertec/api-client'
import { SPageState } from '@sertec/ui'
import { enterTable } from '../shop'

/** /mesa/:token — QR Code da mesa: liga o modo mesa e abre o cardápio. */
const props = defineProps<{ token: string }>()
const router = useRouter()
const failed = ref<string | null>(null)

onMounted(async () => {
  try {
    const t = await tableApi.get(props.token)
    if (!t.branch.id) throw new Error('sem unidade')
    enterTable({ token: props.token, label: t.label, branchId: t.branch.id })
    await router.replace({ name: 'menu' })
  } catch (e) {
    failed.value = e instanceof ApiError && e.code === 'feature_unavailable'
      ? 'Este restaurante não recebe pedidos pela mesa. Chame o garçom.'
      : 'Não encontramos esta mesa. Peça ajuda ao garçom.'
  }
})
</script>

<template>
  <SPageState v-if="failed" state="unavailable" title="Mesa não encontrada" :message="failed" class="min-h-dvh" />
  <SPageState v-else state="loading" class="min-h-dvh" />
</template>

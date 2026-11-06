<script setup lang="ts">
import { computed } from 'vue'
import { SPageState } from '@sertec/ui'
import { can } from '../session'

/** Tela de um módulo que o restaurante não contratou. */
const props = defineProps<{ modulo: string }>()
const NAMES: Record<string, { name: string; what: string }> = {
  online_ordering: { name: 'Cardápio digital', what: 'loja online com carrinho, pedidos pelo site, entrega e pagamento online' },
  cashbook: { name: 'PDV e caixa', what: 'venda no balcão, caixa, taxas, custos fixos e resultado' },
  tabs: { name: 'Mesas e comandas', what: 'contas por mesa, envio para a cozinha e fechamento com taxa de serviço' },
  stock: { name: 'Estoque', what: 'saldo por produto, baixa automática e esgotado sozinho' },
}
const mod = computed(() => NAMES[props.modulo] ?? { name: 'Este recurso', what: '' })
</script>

<template>
  <SPageState
    state="unavailable"
    :title="`${mod.name} não está no seu plano`"
    :message="`${mod.what ? `O módulo ${mod.name} traz ${mod.what}. ` : ''}Para contratar, fale com a equipe SerTec${can('billing.manage') ? ' ou veja sua assinatura' : ''}.`"
  />
  <p v-if="can('billing.manage')" class="mt-4 text-center"><RouterLink to="/assinatura" class="font-semibold text-brand-700 underline">Ver minha assinatura</RouterLink></p>
</template>

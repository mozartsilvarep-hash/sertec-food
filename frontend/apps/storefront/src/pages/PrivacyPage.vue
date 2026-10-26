<script setup lang="ts">
import { computed } from 'vue'
import BackHeader from '../components/BackHeader.vue'
import { store } from '../shop'

/**
 * Política de privacidade do cardápio. Modelo padrão da plataforma: o
 * restaurante é o controlador; a SerTec Solution, operadora. Revisar com
 * assessoria jurídica antes do piloto (docs/OPERATIONS.md).
 */
const name = computed(() => store.value?.name ?? 'o estabelecimento')
const months = 24
</script>

<template>
  <BackHeader title="Privacidade" />
  <main class="mx-auto max-w-2xl px-4 pt-6 pb-16 leading-relaxed">
    <h2 class="font-display text-2xl font-bold">Como {{ name }} cuida dos seus dados</h2>
    <p class="mt-2 text-sm text-muted">Política de privacidade deste cardápio digital.</p>

    <section class="mt-6 grid gap-2">
      <h3 class="font-semibold">Quem é responsável</h3>
      <p>{{ name }} é o responsável (controlador) pelos dados do seu pedido. A plataforma do cardápio é operada pela SerTec Solution, que trata os dados apenas para fazer o pedido funcionar, seguindo as instruções do estabelecimento.</p>
    </section>

    <section class="mt-6 grid gap-2">
      <h3 class="font-semibold">Quais dados usamos e por quê</h3>
      <ul class="list-disc pl-5">
        <li><strong>Nome e celular:</strong> identificar e falar com você sobre o pedido.</li>
        <li><strong>Endereço:</strong> só para entrega.</li>
        <li><strong>E-mail (opcional; obrigatório no Pix):</strong> enviar o comprovante de pagamento.</li>
        <li><strong>Itens, valores e observações:</strong> preparar e entregar o pedido e cumprir obrigações fiscais.</li>
      </ul>
      <p>Não vendemos seus dados e não usamos cookies de rastreamento ou publicidade. O site guarda no seu aparelho apenas o carrinho, a unidade escolhida, o último pedido e os dados do último pedido (nome, celular e endereço), para preencher o próximo mais rápido. Você pode apagar esses dados em "Esquecer meus dados", na hora de finalizar o pedido.</p>
      <p>Ao digitar seu celular em outro aparelho, mostramos só seu primeiro nome, a rua e o bairro do último endereço, com o número escondido. Em "Acompanhar pedido" pelo celular aparecem só o número, a situação e o valor dos pedidos das últimas 24 horas.</p>
    </section>

    <section class="mt-6 grid gap-2">
      <h3 class="font-semibold">Pagamentos</h3>
      <p>Pix e cartão pelo site são processados pelo Mercado Pago. Os dados do cartão são digitados no ambiente do Mercado Pago e nunca passam pelo cardápio nem pelo estabelecimento.</p>
    </section>

    <section class="mt-6 grid gap-2">
      <h3 class="font-semibold">Por quanto tempo</h3>
      <p>Seus dados pessoais ficam guardados por até {{ months }} meses após o pedido. Depois disso, nome, telefone, e-mail, endereço e observações são apagados automaticamente; ficam só os valores e itens, exigidos pela contabilidade.</p>
    </section>

    <section class="mt-6 grid gap-2">
      <h3 class="font-semibold">Seus direitos</h3>
      <p>Você pode pedir para ver, corrigir ou apagar seus dados a qualquer momento. Fale com {{ name }}<template v-if="store?.branches?.[0]?.phone"> pelo {{ store.branches[0].phone }}</template> informando o celular usado nos pedidos.</p>
    </section>

    <RouterLink to="/" class="mt-10 block text-center font-semibold text-brand-700 hover:underline">Voltar ao cardápio</RouterLink>
  </main>
</template>

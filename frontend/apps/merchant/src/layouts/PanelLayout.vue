<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { formatDay, formatMoney, merchantApi } from '@sertec/api-client'
import { SButton, SSignature } from '@sertec/ui'
import LiveBar from '../components/LiveBar.vue'
import { startLive, stopLive } from '../live'
import { notice } from '../notify'
import { can, has, me } from '../session'

const router = useRouter()
const route = useRoute()

/** Ícones do menu (traço 24×24, mesmo estilo dos outros ícones do app). */
const icons: Record<string, string> = {
  orders: 'M9 5h6M9 3h6a1 1 0 0 1 1 1v1H8V4a1 1 0 0 1 1-1zM6 5h12v16H6zM9 11h6M9 15h4',
  history: 'M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5M12 7v5l3 2',
  pos: 'M4 4h16v12H4zM8 20h8M12 16v4M8 8h3M8 11h5',
  tabs: 'M4 9h16M6 9v11M18 9v11M9 5h6M12 5v4',
  waiter: 'M7 3h10v18H7zM10 18h4M9 7h6',
  stock: 'M3 7l9-4 9 4-9 4-9-4zM3 7v10l9 4 9-4V7M12 11v10',
  cash: 'M3 7h18v10H3zM12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM6 10v4M18 10v4',
  finance: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
  reports: 'M4 4h16v16H4zM8 16v-4M12 16V8M16 16v-6',
  branches: 'M3 21h18M5 21V8l7-5 7 5v13M9 21v-6h6v6',
  catalog: 'M4 5h16M4 12h16M4 19h10',
  options: 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0M16 4v4M10 10v4M18 16v4',
  soldout: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM5.6 5.6l12.8 12.8',
  marketing: 'M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1zM15 9a3 3 0 0 1 0 6M18 6a7 7 0 0 1 0 12',
  hours: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 2',
  delivery: 'M3 7h11v9H3zM14 10h4l3 3v3h-7M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
  brand: 'M12 3a9 9 0 0 0 0 18c1 0 1.5-.8 1.5-1.5 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.8.7-1.5 1.5-1.5H16a5 5 0 0 0 5-5c0-4.4-4-7.8-9-7.8zM7.5 11.5h0M10 7.5h0M14.5 7.5h0',
  payments: 'M3 6h18v12H3zM3 10h18M7 15h3',
  team: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 12 0v1M16 3.5a4 4 0 0 1 0 7.5M22 21v-1a6 6 0 0 0-4-5.6',
  privacy: 'M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3zM9 12l2 2 4-4',
  audit: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-5-5M8 11h6',
  billing: 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6',
  account: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21v-1a8 8 0 0 1 16 0v1',
}

interface NavLink { to: string; label: string; icon: string; show: boolean; hint: string }

/** Menu organizado por assunto (o rótulo do grupo só aparece no computador). */
const groups = computed(() => ([
  { title: 'Operação', links: [
    { to: '/pedidos', label: 'Pedidos', icon: 'orders', show: can('orders.view'), hint: 'Pedidos em andamento, ao vivo' },
    { to: '/historico', label: 'Histórico', icon: 'history', show: can('orders.view'), hint: 'Pedidos anteriores, busca e exportação' },
    { to: '/pdv', label: 'PDV', icon: 'pos', show: can('cash.operate') && has('cashbook'), hint: 'Venda rápida no balcão' },
    { to: '/garcom', label: 'Garçom (celular)', icon: 'waiter', show: can('tabs.operate') && has('tabs'), hint: 'Salão no celular: lançar, cozinha e pedidos do QR' },
    { to: '/mesas', label: 'Mesas e comandas', icon: 'tabs', show: can('cash.operate') && has('tabs'), hint: 'Contas abertas, cozinha e fechamento' },
    { to: '/caixa', label: 'Caixa', icon: 'cash', show: can('cash.operate') && has('cashbook'), hint: 'Abrir, movimentar e fechar o caixa' },
    { to: '/financeiro', label: 'Financeiro', icon: 'finance', show: can('cash.manage') && has('cashbook'), hint: 'Taxas, custos fixos e resultado' },
    { to: '/relatorios', label: 'Relatórios', icon: 'reports', show: can('reports.view'), hint: 'Vendas por período, forma e produto' },
  ] },
  { title: 'Cardápio', links: [
    { to: '/cardapio', label: 'Cardápio', icon: 'catalog', show: can('catalog.view'), hint: 'Categorias, produtos e preços' },
    { to: '/opcoes', label: 'Opções e sabores', icon: 'options', show: can('catalog.view'), hint: 'Adicionais, bordas e sabores de pizza' },
    { to: '/estoque', label: 'Estoque', icon: 'stock', show: can('catalog.view') && has('stock'), hint: 'Saldo por unidade, entradas e perdas' },
    { to: '/esgotados', label: 'Esgotados', icon: 'soldout', show: can('catalog.availability'), hint: 'Marcar o que acabou hoje' },
    { to: '/promocoes', label: 'Promoções', icon: 'marketing', show: can('marketing.manage'), hint: 'Promoções, cupons, fidelidade e vitrine' },
  ] },
  { title: 'Loja', links: [
    { to: '/', label: 'Unidades', icon: 'branches', show: can('branches.view'), hint: 'Endereços e contatos das unidades' },
    { to: '/horarios', label: 'Horários', icon: 'hours', show: can('branches.view') && has('online_ordering'), hint: 'Dias e horários de funcionamento' },
    { to: '/entrega', label: 'Entrega', icon: 'delivery', show: can('branches.view') && has('online_ordering'), hint: 'Retirada, bairros e taxas de entrega' },
    { to: '/marca', label: 'Marca', icon: 'brand', show: can('settings.view'), hint: 'Logo, capa, cores e tema da loja' },
    { to: '/pagamentos', label: 'Pagamentos', icon: 'payments', show: can('payments.manage') && has('online_ordering'), hint: 'Mercado Pago e formas de pagamento' },
  ] },
  { title: 'Conta', links: [
    { to: '/equipe', label: 'Equipe', icon: 'team', show: can('staff.manage'), hint: 'Pessoas com acesso ao painel' },
    { to: '/privacidade', label: 'Privacidade (LGPD)', icon: 'privacy', show: can('settings.update'), hint: 'Relatório e exclusão de dados de clientes' },
    { to: '/auditoria', label: 'Auditoria', icon: 'audit', show: can('audit.view'), hint: 'Quem fez o quê, e quando' },
    { to: '/assinatura', label: 'Assinatura', icon: 'billing', show: can('billing.manage'), hint: 'Mensalidade SerTec e faturas' },
    { to: '/minha-conta', label: 'Minha conta', icon: 'account', show: true, hint: 'Seus dados e sua senha' },
  ] },
] as { title: string; links: NavLink[] }[])
  // Bloqueado por atraso: só Assinatura e Minha conta.
  .map((g) => ({ ...g, links: g.links.filter((l) => l.show && (!me.value?.blocked || ['/assinatura', '/minha-conta'].includes(l.to))) }))
  .filter((g) => g.links.length > 0))

/** "Cardápio" continua marcado em /cardapio/produtos/123; "Unidades" (/) só na própria página. */
function isActive(to: string): boolean {
  const path = route.path
  return to === '/' ? path === '/' : path === to || path.startsWith(`${to}/`)
}

// Celular: o menu é uma faixa que rola de lado; a seção aberta fica visível.
const nav = ref<HTMLElement | null>(null)
watch(() => route.path, async () => {
  await nextTick()
  nav.value?.querySelector<HTMLElement>('[aria-current="page"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })
}, { immediate: true })

const live = can('orders.view')

async function logout() {
  stopLive()
  await merchantApi.logout().catch(() => undefined)
  me.value = null
  await router.replace({ name: 'login' })
}

onMounted(() => {
  if (live) {
    startLive(() => {
      me.value = null
      void router.replace({ name: 'login' })
    })
  }
})
onBeforeUnmount(stopLive)
</script>

<template>
  <div class="min-h-dvh md:grid md:grid-cols-[15rem_1fr]">
    <aside class="border-b border-line bg-surface md:sticky md:top-0 md:flex md:h-dvh md:flex-col md:overflow-y-auto md:border-r md:border-b-0">
      <div class="flex items-center justify-between gap-3 px-4 py-4 md:block md:px-5 md:pt-6 md:pb-4">
        <div class="min-w-0">
          <p class="line-clamp-2 font-display text-lg leading-tight font-bold text-ink">{{ me?.tenant.name }}</p>
          <p class="truncate text-sm text-muted">{{ me?.user.name }}</p>
        </div>
        <SButton variant="ghost" class="shrink-0 md:mt-3 md:-ml-4" aria-label="Sair do painel" @click="logout">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H3" /></svg>
          Sair
        </SButton>
      </div>
      <nav ref="nav" aria-label="Seções do painel" class="md:flex-1">
        <ul class="relative flex gap-1 overflow-x-auto px-3 pb-3 [scrollbar-width:none] md:flex-col md:overflow-visible">
          <template v-for="(group, gi) in groups" :key="group.title">
            <li class="hidden px-3 pb-1 text-[0.7rem] font-semibold tracking-[0.12em] text-muted/80 uppercase md:block" :class="gi > 0 && 'pt-4'" aria-hidden="true">
              {{ group.title }}
            </li>
            <li v-for="link in group.links" :key="link.to">
              <RouterLink
                :to="link.to"
                :title="link.hint"
                class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors"
                :class="isActive(link.to) ? 'bg-brand-50 text-brand-700' : 'text-muted hover:bg-canvas hover:text-ink'"
                :aria-current="isActive(link.to) ? 'page' : undefined"
                active-class=""
                exact-active-class=""
              >
                <svg viewBox="0 0 24 24" class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons[link.icon]" /></svg>
                {{ link.label }}
              </RouterLink>
            </li>
          </template>
        </ul>
      </nav>
      <SSignature class="hidden px-5 pt-4 pb-6 md:block" />
    </aside>

    <div class="min-w-0">
      <LiveBar v-if="live && !me?.blocked" />
      <p
        v-if="me?.blocked && $route.name !== 'billing'"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-danger px-4 py-2.5 text-sm text-white md:px-8"
        role="alert"
      >
        <span><strong>Sistema bloqueado</strong> por mensalidade em atraso. A loja online, o PDV e as mesas estão parados.</span>
        <RouterLink v-if="can('billing.manage')" to="/assinatura" class="font-semibold underline underline-offset-2">Pagar e liberar agora</RouterLink>
        <span v-else>Avise o responsável pelo restaurante.</span>
      </p>
      <p
        v-else-if="me?.billing && $route.name !== 'billing'"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm md:px-8"
        :class="me.billing.overdue ? 'bg-danger text-white' : 'bg-warning/10 text-ink'"
        role="status"
      >
        <span>
          {{ me.billing.overdue ? 'Mensalidade em atraso' : 'Sua mensalidade vence em breve' }}:
          <strong>{{ formatMoney(me.billing.amount) }}</strong>, vencimento {{ formatDay(me.billing.due_date) }}.
          <template v-if="me.billing.block_date"> O sistema será bloqueado em <strong>{{ formatDay(me.billing.block_date) }}</strong>.</template>
        </span>
        <RouterLink to="/assinatura" class="font-semibold underline underline-offset-2">Pagar com Pix</RouterLink>
      </p>
      <main class="min-w-0 px-4 py-6 md:px-8 md:py-8">
        <RouterView />
      </main>
    </div>

    <div
      v-if="notice"
      role="status"
      class="fixed inset-x-4 bottom-4 z-50 mx-auto max-w-sm rounded-xl px-4 py-3 text-sm font-medium shadow-lg md:right-6 md:left-auto"
      :class="notice.tone === 'ok' ? 'bg-ink text-white' : 'bg-danger text-white'"
    >
      {{ notice.text }}
    </div>
  </div>
</template>

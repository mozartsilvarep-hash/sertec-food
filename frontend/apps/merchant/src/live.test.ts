import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import LiveBar from './components/LiveBar.vue'
import { pulse, startLive, stopLive } from './live'

function respond(data: { version: string; pending: number; oldest_pending_at: string | null }) {
  return Promise.resolve(new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } }))
}

afterEach(() => {
  stopLive()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('pedidos ao vivo', () => {
  it('pedido novo atualiza a faixa e o título; atraso de 5 min fica em destaque', async () => {
    vi.useFakeTimers()
    document.title = 'Painel'
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockImplementationOnce(() => respond({ version: 'a', pending: 0, oldest_pending_at: null }))
      .mockImplementationOnce(() => respond({ version: 'b', pending: 2, oldest_pending_at: new Date(Date.now() - 7 * 60_000).toISOString() }))

    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<p/>' } }, { path: '/pedidos', component: { template: '<p/>' } }] })
    await router.push('/')
    const wrapper = mount(LiveBar, { global: { plugins: [router] } })
    startLive(() => undefined)
    await flushPromises()
    expect(wrapper.text()).toContain('nenhum pedido esperando')

    await vi.advanceTimersByTimeAsync(3_000)
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/merchant/orders/pulse', expect.anything())
    expect(pulse.value?.pending).toBe(2)
    expect(document.title).toBe('(2) Novos pedidos · Painel')
    expect(wrapper.text()).toContain('2 pedidos novos')
    expect(wrapper.text()).toContain('espera há 7 min')
    expect(wrapper.find('[aria-label="Pedidos ao vivo"]').classes()).toContain('bg-danger')
    expect(wrapper.find('a').attributes('href')).toBe('/pedidos')
    wrapper.unmount()
  })

  it('sessão expirada para o pulso e avisa o painel', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}', { status: 401 }))
    const onUnauthorized = vi.fn()
    startLive(onUnauthorized)
    await flushPromises()
    expect(onUnauthorized).toHaveBeenCalled()
    expect(pulse.value).toBeNull()
  })
})

import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiError, request } from './http'

function mockFetch(status: number, body: unknown) {
  const fn = vi.fn().mockResolvedValue(
    new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
  )
  vi.stubGlobal('fetch', fn)
  return fn
}

afterEach(() => {
  vi.unstubAllGlobals()
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
})

describe('request', () => {
  it('usa a mesma origem, envia cookies e nunca envia tenant_id', async () => {
    const fetchMock = mockFetch(200, { data: { ok: true } })

    await request('/merchant/me')

    const [url, init] = fetchMock.mock.calls[0]!
    expect(url).toBe('/api/v1/merchant/me')
    expect(init.credentials).toBe('same-origin')
    expect(JSON.stringify(init)).not.toMatch(/tenant/i)
  })

  it('envia o token CSRF do cookie em requisições de escrita', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D123'
    const fetchMock = mockFetch(200, {})

    await request('/merchant/auth/login', { method: 'POST', body: { email: 'a@b.c', password: 'x' } })

    expect(fetchMock.mock.calls[0]![1].headers['X-XSRF-TOKEN']).toBe('abc=123')
  })

  it('converte o formato padrão de erro em ApiError', async () => {
    mockFetch(422, {
      code: 'validation_failed',
      message: 'Dados inválidos.',
      details: { fields: { email: ['Obrigatório'] } },
      request_id: 'req-1',
    })

    const error = await request('/x').catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 422, code: 'validation_failed', requestId: 'req-1' })
    expect((error as ApiError).fieldErrors.email).toEqual(['Obrigatório'])
  })

  it('falha de rede vira ApiError network_error', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    await expect(request('/x')).rejects.toMatchObject({ status: 0, code: 'network_error' })
  })
})

/**
 * Cliente HTTP das SPAs. API e SPA ficam na mesma origem (Nginx), então:
 * - cookies de sessão vão automaticamente (credentials: 'same-origin');
 * - nunca enviamos tenant_id: o servidor resolve o tenant pelo Host.
 */

export interface ApiErrorBody {
  code: string
  message: string
  details: Record<string, unknown> | null
  request_id: string | null
}

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly details: Record<string, unknown> | null
  readonly requestId: string | null

  constructor(status: number, body: Partial<ApiErrorBody>) {
    super(body.message ?? 'Erro inesperado. Tente novamente.')
    this.name = 'ApiError'
    this.status = status
    this.code = body.code ?? 'unknown_error'
    this.details = body.details ?? null
    this.requestId = body.request_id ?? null
  }

  /** Erros de validação por campo: { email: ['...'] } */
  get fieldErrors(): Record<string, string[]> {
    const fields = this.details?.fields
    return fields && typeof fields === 'object' ? (fields as Record<string, string[]>) : {}
  }
}

export const API_BASE = '/api/v1'

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  /** Envio multipart (upload). Mutuamente exclusivo com body. */
  form?: Record<string, string | Blob>
  signal?: AbortSignal
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  }

  let payload: BodyInit | undefined
  if (options.form !== undefined) {
    const data = new FormData()
    for (const [key, value] of Object.entries(options.form)) data.append(key, value)
    payload = data // o navegador define o boundary do multipart
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(options.body)
  }

  // Defesa adicional ao Sec-Fetch-Site: token CSRF do cookie XSRF-TOKEN.
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf && method !== 'GET') {
    headers['X-XSRF-TOKEN'] = xsrf
  }

  let response: Response
  try {
    response = await fetch(`${API_BASE}${path}`, {
      method,
      headers,
      credentials: 'same-origin',
      body: payload,
      signal: options.signal,
    })
  } catch {
    throw new ApiError(0, { code: 'network_error', message: 'Sem conexão. Verifique sua internet.' })
  }

  if (response.status === 204) {
    return undefined as T
  }

  const json: unknown = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(response.status, json as Partial<ApiErrorBody>)
  }

  return json as T
}

/** Mensagem amigável + erros por campo, para formulários. */
export function describeError(error: unknown): { message: string; fields?: Record<string, string[]> } {
  if (error instanceof ApiError) {
    const fields = error.fieldErrors
    return Object.keys(fields).length > 0 ? { message: error.message, fields } : { message: error.message }
  }
  return { message: 'Erro inesperado. Tente novamente.' }
}

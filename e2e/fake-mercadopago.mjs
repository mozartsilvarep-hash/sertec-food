// Mercado Pago falso para o E2E local (só os endpoints usados pelo gateway).
import http from 'node:http'
import QRCode from 'qrcode'

const TOKEN = 'TEST-1111222233334444-102512-e2eloja-0001'
const COLLECTOR = 777001
const payments = new Map()
const idem = new Map()
let next = 5_100_000_001
const log = []

const send = (res, status, body) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(body)) }

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x')
  let raw = ''
  for await (const c of req) raw += c
  const body = raw ? JSON.parse(raw) : {}
  log.push({ method: req.method, path: url.pathname, idem: req.headers['x-idempotency-key'] ?? null, body })

  // Controle do teste
  if (url.pathname === '/__log') return send(res, 200, log)
  if (url.pathname === '/__last') return send(res, 200, [...payments.values()].at(-1) ?? null)
  const ctl = url.pathname.match(/^\/__set\/(\d+)\/(\w+)$/)
  if (ctl) { payments.get(ctl[1]).status = ctl[2]; return send(res, 200, payments.get(ctl[1])) }

  if (req.headers.authorization !== `Bearer ${TOKEN}`) return send(res, 401, { message: 'invalid access token', error: 'unauthorized' })

  if (req.method === 'GET' && url.pathname === '/users/me') return send(res, 200, { id: COLLECTOR, nickname: 'PIZZARIA_DEMO_TESTE', site_id: 'MLB' })

  if (req.method === 'POST' && url.pathname === '/v1/payments') {
    const key = req.headers['x-idempotency-key']
    if (key && idem.has(key)) return send(res, 201, payments.get(idem.get(key)))
    const id = String(next++)
    const code = `00020126580014br.gov.bcb.pix0136${id}-e2e5204000053039865802BR5913PIZZARIA DEMO6007MACEIO62070503***6304ABCD`
    const png = await QRCode.toBuffer(code, { margin: 1, width: 240 })
    const p = {
      id: Number(id), status: 'pending', status_detail: 'pending_waiting_transfer', currency_id: 'BRL', collector_id: COLLECTOR, live_mode: false,
      external_reference: body.external_reference, transaction_amount: body.transaction_amount, transaction_amount_refunded: 0,
      date_of_expiration: body.date_of_expiration, payment_method_id: 'pix',
      point_of_interaction: { type: 'PIX', transaction_data: { qr_code: code, qr_code_base64: png.toString('base64'), ticket_url: `https://www.mercadopago.com.br/payments/${id}/ticket` } },
    }
    payments.set(id, p); if (key) idem.set(key, id)
    return send(res, 201, p)
  }
  if (req.method === 'POST' && url.pathname === '/checkout/preferences') {
    return send(res, 201, { id: `777001-pref-${next++}`, init_point: 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=e2e', sandbox_init_point: 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=e2e' })
  }
  if (req.method === 'GET' && url.pathname === '/v1/payments/search') {
    return send(res, 200, { results: [...payments.values()].filter((p) => p.external_reference === url.searchParams.get('external_reference')) })
  }
  let m = url.pathname.match(/^\/v1\/payments\/(\d+)$/)
  if (req.method === 'GET' && m) return payments.has(m[1]) ? send(res, 200, payments.get(m[1])) : send(res, 404, { error: 'not_found' })
  m = url.pathname.match(/^\/v1\/payments\/(\d+)\/refunds$/)
  if (req.method === 'POST' && m) {
    const p = payments.get(m[1])
    const amount = body.amount ?? (p.transaction_amount - p.transaction_amount_refunded)
    p.transaction_amount_refunded = Math.round((p.transaction_amount_refunded + amount) * 100) / 100
    if (p.transaction_amount_refunded >= p.transaction_amount) p.status = 'refunded'
    return send(res, 201, { id: next++, payment_id: p.id, amount, status: 'approved' })
  }
  send(res, 404, { message: 'not found' })
}).listen(9911, '127.0.0.1', () => console.log('fake MP on 9911'))

import { launch, reporter, SHOTS, TENANT, OTHER_TENANT, ADMIN, PASSWORDS, BASE_DOMAIN } from './lib.mjs'
import crypto from 'node:crypto'
import http from 'node:http'

const ownerPw = PASSWORDS.owner
const out = SHOTS
const HOST = TENANT
const MP = 'http://127.0.0.1:9911'
const TOKEN = 'TEST-1111222233334444-102512-e2eloja-0001'
const SECRET = 'e2eWebhookSecret0123456789'
const { check, done } = reporter()
const NAME = `João Lima ${String(Date.now()).slice(-4)}`
const browser = await launch()

async function webhook(dataId, secret = SECRET) {
  const requestId = crypto.randomUUID()
  const ts = String(Math.floor(Date.now() / 1000))
  const v1 = crypto.createHmac('sha256', secret).update(`id:${dataId};request-id:${requestId};ts:${ts};`).digest('hex')
  const payload = JSON.stringify({ id: Date.now(), live_mode: false, type: 'payment', action: 'payment.updated', data: { id: dataId } })
  return await new Promise((resolve, reject) => {
    const req = http.request({
      host: '127.0.0.1', port: 80, method: 'POST', path: `/api/v1/webhooks/mercadopago?data.id=${dataId}&type=payment`,
      headers: { Host: `pizzaria-demo.${BASE_DOMAIN}`, 'Content-Type': 'application/json', Accept: 'application/json', 'x-signature': `ts=${ts},v1=${v1}`, 'x-request-id': requestId },
    }, (res) => {
      let data = ''
      res.on('data', (c) => (data += c))
      res.on('end', () => resolve({ status: res.statusCode, body: JSON.parse(data || '{}') }))
    })
    req.on('error', reject)
    req.end(payload)
  })
}

// 1. Dono conecta o Mercado Pago
const panel = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(ownerPw)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)
await m.getByRole('link', { name: 'Pagamentos' }).click()
await m.getByRole('heading', { name: 'Pagamentos online' }).waitFor()
await m.getByText('/api/v1/webhooks/mercadopago').waitFor()
check('painel mostra o endereço do webhook', true)
await m.screenshot({ path: `${out}/s3-1-pagamentos-conectar.png`, fullPage: true })
await m.getByLabel('Access Token').fill('TEST-token-que-nao-existe-000000000')
await m.getByRole('button', { name: 'Conectar Mercado Pago' }).click()
await m.getByRole('alert').waitFor()
check('token recusado mostra erro', (await m.getByRole('alert').textContent()).includes('recusou'))
await m.getByLabel('Access Token').fill(TOKEN)
await m.getByLabel(/Assinatura secreta/).fill(SECRET)
await m.getByRole('button', { name: 'Conectar Mercado Pago' }).click()
await m.getByText('Conectado: PIZZARIA_DEMO_TESTE').waitFor()
const conn = await m.locator('main').textContent()
check('conectado em modo de teste, token só pelo final', conn.includes('Modo de teste') && conn.includes('terminando em 0001') && !conn.includes(TOKEN))
await m.screenshot({ path: `${out}/s3-2-pagamentos-conectado.png`, fullPage: true })

// 2. Cliente paga com Pix
const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await shop.newPage()
await p.goto(HOST + '/')
await p.getByRole('heading', { name: 'Pizzaria Demo', level: 1 }).waitFor()
await p.getByRole('button', { name: /Refrigerante lata/ }).click()
await p.getByRole('button', { name: 'Adicionar quantidade' }).click()
await p.getByRole('button', { name: 'Adicionar quantidade' }).click()
await p.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await p.getByText('Refrigerante lata adicionado').waitFor()
await p.getByRole('button', { name: /Pizza tradicional/ }).click()
await p.getByRole('dialog').waitFor()
await p.getByText('Grande', { exact: true }).click()
await p.getByLabel(/Calabresa/).check()
await p.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await p.getByText('Pizza tradicional adicionado').waitFor()
await p.getByRole('link', { name: /Ver carrinho/ }).click()
await p.getByRole('button', { name: 'Continuar' }).click()
await p.getByRole('heading', { name: 'Finalizar pedido' }).waitFor()
await p.getByText('Retirar no local', { exact: true }).click()
await p.getByLabel('Nome').fill(NAME)
await p.getByLabel('Celular com DDD').fill('(82) 98888-1111')
check('Pix vem marcado por padrão', await p.getByLabel(/^Pix/).isChecked())
await p.getByLabel('E-mail para o comprovante').fill('joao@example.com')
await p.screenshot({ path: `${out}/s3-3-checkout-pix.png`, fullPage: true })
const btn = (await p.getByRole('button', { name: /Ir para o pagamento/ }).textContent()).trim()
await p.getByRole('button', { name: /Ir para o pagamento/ }).click()
await p.waitForURL(/\/pedido\/[a-f0-9]{40}$/)
const trackUrl = p.url()
await p.getByRole('heading', { name: 'Pague com Pix' }).waitFor()
await p.getByAltText('QR Code do Pix').waitFor()
const qrOk = await p.getByAltText('QR Code do Pix').evaluate((img) => img.complete && img.naturalWidth > 100)
check('QR Code do Pix renderiza', qrOk, btn)
check('contagem regressiva visível', /Expira em (29|30):\d\d/.test(await p.locator('main').textContent()))
await p.screenshot({ path: `${out}/s3-4-pix-qr.png`, fullPage: true })

// 3. Restaurante ainda não vê o pedido
await m.getByRole('link', { name: 'Pedidos' }).click()
await m.waitForTimeout(800)
const before = await m.locator('main').textContent()
const last = await (await fetch(`${MP}/__last`)).json()
check('pedido não pago não aparece no painel', !before.includes(NAME))
const pix = (await (await fetch(`${MP}/__log`)).json()).filter((l) => l.path === '/v1/payments' && l.method === 'POST').at(-1)
check('cobrança com X-Idempotency-Key e valor do servidor', pix.idem?.length === 26 && pix.body.transaction_amount === last.transaction_amount, `R$ ${last.transaction_amount}`)

// 4. Assinatura inválida é recusada; cliente paga; webhook assinado confirma
const bad = await webhook(String(last.id), 'assinatura-falsa-000000000')
check('webhook com assinatura inválida → 401', bad.status === 401)
await fetch(`${MP}/__set/${last.id}/approved`)
const ok = await webhook(String(last.id))
check('webhook assinado (query data.id crua via Nginx/FPM) → 200 received', ok.status === 200 && ok.body.status === 'received', JSON.stringify(ok.body))
await p.getByRole('heading', { name: 'Aguardando confirmação' }).waitFor({ timeout: 15000 })
check('cliente vê o pagamento confirmado sozinho (sem recarregar)', true)
await p.screenshot({ path: `${out}/s3-5-pix-pago.png`, fullPage: true })

// 5. Painel: pedido entra com selo "Pago online"; reembolso parcial
await m.reload()
const card = m.locator('section[aria-label="Novos"] li', { hasText: NAME })
await card.waitFor({ timeout: 20000 })
check('pedido pago entra no painel com selo', (await card.textContent()).includes('Pago online'))
await card.getByRole('button').click()
await m.getByRole('dialog').waitFor()
await m.getByRole('dialog').getByText('Mercado Pago nº').waitFor()
await m.screenshot({ path: `${out}/s3-6-painel-pedido-pago.png` })
await m.getByLabel('Valor do reembolso').fill('5,00')
await m.getByLabel('Motivo do reembolso').fill('Refrigerante faltou')
await m.getByRole('button', { name: 'Reembolsar' }).click()
await m.getByRole('button', { name: /Confirmar reembolso de R\$ 5,00/ }).click()
await m.getByText(/Reembolso de R\$\s5,00 enviado/).waitFor()
const det = await m.getByRole('dialog').textContent()
check('reembolso parcial registrado', det.includes('reembolsado em parte') && det.includes('Reembolso R$'))
await m.screenshot({ path: `${out}/s3-7-reembolso.png` })

// 6. Cartão: link do Checkout Pro, retorno sem token na URL
const c2 = await shop.newPage()
await c2.goto(HOST + '/')
await c2.getByRole('button', { name: /Refrigerante lata/ }).click()
await c2.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await c2.getByText('Refrigerante lata adicionado').waitFor()
await c2.getByRole('button', { name: /Pizza tradicional/ }).click()
await c2.getByText('Grande', { exact: true }).click()
await c2.getByLabel(/Calabresa/).check()
await c2.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await c2.getByText('Pizza tradicional adicionado').waitFor()
await c2.getByRole('link', { name: /Ver carrinho/ }).click()
await c2.getByRole('button', { name: 'Continuar' }).click()
await c2.getByText('Retirar no local', { exact: true }).click()
await c2.getByLabel('Nome').fill('Ana Rocha')
await c2.getByLabel('Celular com DDD').fill('(82) 97777-2222')
await c2.getByLabel(/Cartão de crédito ou débito/).check()
await c2.getByRole('button', { name: /Ir para o pagamento/ }).click()
await c2.waitForURL(/\/pedido\/[a-f0-9]{40}$/)
const link = c2.getByRole('link', { name: 'Pagar com cartão' })
await link.waitFor()
check('cartão leva ao Checkout Pro (sandbox)', (await link.getAttribute('href')).startsWith('https://sandbox.mercadopago.com.br/'))
await c2.screenshot({ path: `${out}/s3-8-cartao.png`, fullPage: true })
const cardToken = c2.url().split('/').pop()
await c2.goto(`${HOST}/pedido/retorno?collection_status=approved&status=approved`)
await c2.waitForURL(/\/pedido\/[a-f0-9]{40}$/)
await c2.getByRole('heading', { name: 'Aguardando pagamento' }).waitFor()
check('retorno do Checkout Pro volta ao pedido certo e não confia no status da URL', c2.url().endsWith(cardToken))

await browser.close()
done()

import { launch, reporter, SHOTS, TENANT, PASSWORDS, BASE_DOMAIN } from './lib.mjs'
import http from 'node:http'

const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()

function feed(origin) {
  return new Promise((resolve, reject) => {
    const req = http.request({
      host: '127.0.0.1', port: 80, method: 'GET', path: '/api/v1/vitrine/promocoes.json',
      headers: { Host: `pizzaria-demo.${BASE_DOMAIN}`, Accept: 'application/json', Origin: origin },
    }, (res) => {
      let data = ''
      res.on('data', (c) => (data += c))
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: JSON.parse(data || '{}') }))
    })
    req.on('error', reject)
    req.end()
  })
}

// 1. Dono cria promoção, cupom e faixa
const panel = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)
await m.getByRole('link', { name: 'Promoções' }).click()
await m.getByRole('button', { name: 'Nova promoção' }).click()
await m.getByLabel('Produto').selectOption({ label: 'Refrigerante lata' })
await m.getByLabel('Por (R$)').fill('5,00')
await m.getByLabel(/Selo/).fill('Gelado!')
await m.getByLabel(/Destaque/).check()
await m.getByRole('button', { name: 'Criar promoção' }).click()
await m.getByText(/Promoção criada: Refrigerante lata/).waitFor()
check('promoção criada pelo preço "por"', (await m.locator('main').textContent()).includes('No ar'))
await m.screenshot({ path: `${out}/s6-1-promocoes.png`, fullPage: true })

await m.getByRole('tab', { name: 'Cupons' }).click()
await m.getByRole('button', { name: 'Novo cupom' }).click()
await m.getByLabel(/Código/).fill('bemvindo10')
await m.getByLabel(/Descrição para o cliente/).fill('10% no primeiro pedido')
await m.getByLabel('Desconto (%)').fill('10')
await m.getByLabel(/Só no primeiro pedido/).check()
await m.getByRole('button', { name: 'Criar cupom' }).click()
await m.getByText('Cupom BEMVINDO10 criado').waitFor()
check('cupom criado em maiúsculas', (await m.locator('table').textContent()).includes('BEMVINDO10'))
await m.screenshot({ path: `${out}/s6-2-cupons.png`, fullPage: true })

await m.getByRole('tab', { name: 'Vitrine e aviso' }).click()
await m.getByLabel(/Texto \(até 160/).fill('Promoção de inauguração: refrigerante a R$ 5!')
await m.getByLabel(/Sites autorizados/).fill('https://www.site-do-restaurante.example')
await m.getByRole('button', { name: 'Salvar vitrine' }).click()
await m.getByText('Vitrine atualizada').waitFor()
await m.screenshot({ path: `${out}/s6-3-vitrine.png`, fullPage: true })

// 2. Feed v1 para o site do restaurante
const ok = await feed('https://www.site-do-restaurante.example')
const promo = ok.body.promocoes?.[0]
check('feed v1 com CORS para o site cadastrado', ok.status === 200 && ok.headers['access-control-allow-origin'] === 'https://www.site-do-restaurante.example')
check('feed traz promoção com preço de/por, selo e link do produto', promo?.precoDe === 6.5 && promo?.precoPor === 5 && promo?.selo === 'Gelado!' && promo?.destaque === true && promo?.url.includes('?produto='), JSON.stringify(promo))
check('feed traz a faixa de aviso', ok.body.faixa?.texto?.startsWith('Promoção de inauguração'))
const bad = await feed('https://outro-site.example')
check('outro site não recebe permissão CORS', !bad.headers['access-control-allow-origin'])

// 3. Cliente: faixa, preço riscado, link profundo, cupom
const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await shop.newPage()
await p.goto(promo.url.replace(/^https?:\/\/[^/]+/, HOST))
await p.getByRole('dialog').waitFor()
await p.getByRole('dialog').getByRole('button', { name: /^Adicionar ·/ }).waitFor()
check('link do feed abre o produto no cardápio', (await p.getByRole('dialog').textContent()).includes('Refrigerante lata'))
const btn = (await p.getByRole('button', { name: /^Adicionar ·/ }).textContent()).replace(/\s+/g, ' ').trim()
check('ficha mostra preço cheio riscado e promocional', btn.includes('R$ 6,50') && btn.includes('R$ 5,00'), btn)
await p.screenshot({ path: `${out}/s6-4-produto-promo.png` })
await p.getByRole('button', { name: 'Adicionar quantidade' }).click()
await p.getByRole('button', { name: /^Adicionar ·/ }).click()
await p.getByText('Refrigerante lata adicionado').waitFor()
check('faixa de aviso no topo do cardápio', (await p.getByRole('note').textContent()).includes('inauguração'))
check('cardápio mostra selo e preço promocional', (await p.getByRole('button', { name: /Refrigerante lata/ }).first().textContent()).includes('Gelado!'))
await p.screenshot({ path: `${out}/s6-5-cardapio-promo.png` })

await p.getByRole('button', { name: /Pizza tradicional/ }).click()
await p.getByText('Grande', { exact: true }).click()
await p.getByLabel(/Calabresa/).check()
await p.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await p.getByText('Pizza tradicional adicionado').waitFor()
await p.getByRole('link', { name: /Ver carrinho/ }).click()
await p.getByRole('button', { name: 'Continuar' }).click()
await p.getByText('Retirar no local', { exact: true }).click()
await p.getByLabel('Nome').fill('Cliente Novo')
await p.getByLabel('Celular com DDD').fill(`(82) 9${String(Date.now()).slice(-8, -4)}-${String(Date.now()).slice(-4)}`)
await p.getByLabel('Dinheiro').check()
await p.getByRole('button', { name: 'Tem cupom de desconto?' }).click()
await p.getByRole('textbox', { name: 'Cupom' }).fill('bemvindo10')
await p.getByRole('button', { name: 'Aplicar' }).click()
await p.getByText('Cupom BEMVINDO10').waitFor()
const summary = await p.getByRole('region', { name: 'Resumo' }).textContent()
// 2 refrigerantes a 5,00 + pizza grande calabresa 52,90 = 62,90; 10% = 6,29
check('cupom aplica 10% sobre o subtotal com promoção', summary.includes('R$ 62,90') && summary.includes('− R$ 6,29') && summary.includes('R$ 56,61'), summary.replace(/\s+/g, ' '))
await p.screenshot({ path: `${out}/s6-6-checkout-cupom.png`, fullPage: true })
await p.getByRole('button', { name: /Fazer pedido/ }).click()
await p.waitForURL(/\/pedido\/[a-f0-9]{40}$/)
await p.getByText('Cupom BEMVINDO10').waitFor()
const track = await p.locator('main').textContent()
check('pedido registrado com desconto', track.includes('Cupom BEMVINDO10') && track.includes('R$ 56,61'))

await browser.close()
done()

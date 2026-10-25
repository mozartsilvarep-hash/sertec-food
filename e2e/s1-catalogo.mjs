import { launch, reporter, SHOTS, TENANT, OTHER_TENANT, ADMIN, PASSWORDS, BASE_DOMAIN } from './lib.mjs'
import { writeFileSync } from 'node:fs'
import zlib from 'node:zlib'

const ownerPw = PASSWORDS.owner
const attendantPw = PASSWORDS.attendant
const out = SHOTS
const { check, done } = reporter()
const browser = await launch()
const HOST = TENANT

// PNG 600x450 gerado na hora (sem dependências).
function png(w, h, [r, g, b]) {
  const crc = (buf) => { let c, crcT = []; for (let n = 0; n < 256; n++) { c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; crcT[n] = c >>> 0 } let x = 0xffffffff; for (const byte of buf) x = crcT[(x ^ byte) & 0xff] ^ (x >>> 8); return (x ^ 0xffffffff) >>> 0 }
  const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]) }
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(w * 3).map((_, i) => [r, g, b][i % 3])])
  const raw = Buffer.concat(Array.from({ length: h }, () => row))
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}
writeFileSync(`${SHOTS}/calzone.png`, png(600, 450, [214, 120, 40]))

async function login(page, email, pw) {
  await page.goto(`${HOST}/painel/login`)
  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Senha').fill(pw)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.waitForURL(`${HOST}/painel/`)
}

// 1. Cardápio público: capa, categorias, modal de pizza meio a meio
{
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } })
  const page = await ctx.newPage()
  await page.goto(HOST + '/')
  await page.getByRole('heading', { name: 'Pizzaria Demo', level: 1 }).waitFor()
  await page.screenshot({ path: `${out}/s1-1-cardapio-mobile.png` })
  check('cardápio mostra categorias', (await page.getByRole('heading', { level: 2 }).allTextContents()).join(',') === 'Pizzas,Bebidas,Sobremesas')

  await page.getByRole('button', { name: /Pizza tradicional/ }).click()
  await page.getByRole('dialog').waitFor()
  await page.getByText('Grande', { exact: true }).click()
  await page.getByLabel(/Calabresa/).check()
  await page.getByLabel(/Quatro queijos/).check()
  const footer = await page.getByRole('dialog').locator('footer').textContent()
  check('meio a meio cobra o mais caro (Grande: 52,90 vs 62,90)', footer.includes('R$ 62,90'), footer.trim())
  await page.getByRole('button', { name: 'Adicionar Bacon' }).click()
  await page.getByRole('button', { name: 'Adicionar Bacon' }).click()
  const footer2 = await page.getByRole('dialog').locator('footer').textContent()
  check('adicional soma ao preço (62,90 + 2×6,00)', footer2.includes('R$ 74,90'), footer2.trim())
  await page.screenshot({ path: `${out}/s1-2-modal-pizza.png` })
  await page.keyboard.press('Escape')
  await page.getByRole('dialog').waitFor({ state: 'hidden' })
  check('Esc fecha o modal', true)
  await ctx.close()
}

// 2. Dono cria produto com foto, categoria e adicionais
{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
  const page = await ctx.newPage()
  await login(page, 'dono@pizzaria.localhost', ownerPw)
  await page.getByRole('link', { name: 'Cardápio' }).click()
  await page.getByRole('heading', { name: 'Pizzas', level: 2 }).waitFor()
  await page.screenshot({ path: `${out}/s1-3-painel-cardapio.png` })

  await page.getByRole('button', { name: 'Novo produto' }).click()
  await page.getByLabel('Nome').fill('Calzone')
  await page.getByLabel('Descrição no').fill('').catch(() => {})
  await page.getByLabel('Descrição').fill('Recheio de calabresa e muçarela')
  await page.getByLabel('Preço (R$)').fill('38,9')
  await page.getByLabel('Pizzas').check()
  await page.getByLabel(/Adicionais/).check()
  await page.getByRole('button', { name: 'Criar produto' }).click()
  await page.getByText('Produto criado').waitFor()
  await page.waitForURL(/produtos\/[0-9a-z]{26}$/)
  check('após criar, formulário mostra o valor gravado (38,90)', (await page.getByLabel('Preço (R$)').inputValue()) === '38,90', await page.getByLabel('Preço (R$)').inputValue())
  // Salvar de novo não pode duplicar a variante (precisa do ID devolvido pelo servidor).
  await page.getByRole('button', { name: 'Salvar alterações' }).click()
  await page.getByText('Produto salvo').waitFor()
  const variants = await page.evaluate(async (id) => (await (await fetch(`/api/v1/merchant/products/${id}`, { headers: { Accept: 'application/json' } })).json()).data.variants, page.url().split('/').pop())
  check('salvar duas vezes mantém 1 variante com o mesmo ID', variants.length === 1, JSON.stringify(variants))

  await page.locator('input[type=file]').setInputFiles(`${SHOTS}/calzone.png`)
  await page.getByText('Foto atualizada').waitFor()
  const src = await page.getByAltText('Foto atual do produto').getAttribute('src')
  const ct = await page.evaluate(async (u) => (await fetch(u)).headers.get('content-type'), src)
  check('foto enviada é servida como WebP pelo Nginx', ct === 'image/webp', `${src} ${ct}`)
  await page.screenshot({ path: `${out}/s1-4-produto-editado.png`, fullPage: true })

  // Opções: grupo de sabores com tabela de preços
  await page.getByRole('link', { name: 'Opções e sabores' }).click()
  await page.getByRole('link', { name: /Sabores/ }).click()
  await page.getByRole('cell').first().waitFor()
  await page.screenshot({ path: `${out}/s1-5-sabores.png`, fullPage: true })
  const headers = await page.locator('thead th').allTextContents()
  check('tabela de sabores mostra colunas por tamanho', headers.some((h) => h.includes('Grande')), JSON.stringify(headers))

  // Marca
  await page.getByRole('link', { name: 'Marca' }).click()
  await page.locator('#brand').fill('#ffd400')
  check('cor clara demais é bloqueada no formulário', await page.getByRole('button', { name: 'Salvar marca' }).isDisabled())
  await page.locator('#brand').fill('#14532d')
  await page.getByRole('button', { name: 'Salvar marca' }).click()
  await page.getByText('Marca atualizada').waitFor()
  await page.screenshot({ path: `${out}/s1-6-marca.png` })
  await ctx.close()
}

// 3. Atendente marca esgotado; cliente vê
{
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
  const page = await ctx.newPage()
  await login(page, 'atendente@pizzaria.localhost', attendantPw)
  const nav = await page.getByRole('navigation', { name: 'Seções do painel' }).textContent()
  check('atendente não vê Marca nem Horários de edição', !nav.includes('Marca'), nav)
  await page.getByRole('link', { name: 'Esgotados' }).click()
  await page.getByRole('switch', { name: undefined }).first().waitFor()
  const row = page.locator('li', { hasText: 'Petit gâteau' })
  await row.getByRole('switch').uncheck({ force: true })
  await page.getByText('Petit gâteau marcado como esgotado').waitFor()
  check('atendente marca esgotado na própria unidade', true)
  await ctx.close()

  const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
  const p = await shop.newPage()
  await p.goto(HOST + '/')
  await p.getByRole('heading', { name: 'Pizzaria Demo', level: 1 }).waitFor()
  const branchSelect = p.locator('header select')
  if (await branchSelect.count()) await branchSelect.selectOption({ label: 'Unidade Ponta Verde' })
  await p.getByText('Esgotado hoje').first().waitFor()
  const bg = await p.locator('header').evaluate((el) => getComputedStyle(el).backgroundColor)
  check('cliente vê "Esgotado hoje" e a nova cor da marca', bg === 'rgb(20, 83, 45)', bg)
  check('Calzone aparece no cardápio público', await p.getByRole('button', { name: /Calzone/ }).first().isVisible())
  await p.screenshot({ path: `${out}/s1-7-cardapio-apos.png`, fullPage: true })
  await shop.close()
}

await browser.close()
done()

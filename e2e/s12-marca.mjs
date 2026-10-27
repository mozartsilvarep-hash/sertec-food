import { launch, reporter, SHOTS, TENANT, OTHER_TENANT, PASSWORDS } from './lib.mjs'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()

// Imagens de exemplo geradas na hora (logo quadrada e capa larga).
const dir = mkdtempSync(join(tmpdir(), 'marca-'))
const art = await browser.newPage()
await art.setViewportSize({ width: 600, height: 600 })
await art.setContent(`<body style="margin:0"><div style="width:600px;height:600px;display:grid;place-items:center;background:#7c2d12;font:800 230px Georgia,serif;color:#fde68a;letter-spacing:-12px">Pd</div></body>`)
await art.screenshot({ path: join(dir, 'logo.png') })
await art.setViewportSize({ width: 1500, height: 600 })
await art.setContent(`<body style="margin:0"><div style="position:relative;width:1500px;height:600px;overflow:hidden;background:radial-gradient(circle at 70% 40%,#f59e0b,#9a3412 60%,#431407)">
  ${[[180, 330, 260], [620, 120, 180], [980, 380, 300], [1330, 140, 220]].map(([x, y, r]) => `<div style="position:absolute;left:${x - r / 2}px;top:${y - r / 2}px;width:${r}px;height:${r}px;border-radius:50%;background:radial-gradient(circle at 40% 35%,#fde68a,#ea580c 55%,#7c2d12);box-shadow:0 30px 60px #0006"></div>`).join('')}
</div></body>`)
await art.screenshot({ path: join(dir, 'capa.png') })
await art.close()

// 1. Dono envia logo e capa, escolhe a cor e preenche os dados da empresa
const panel = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)
await m.goto(`${HOST}/painel/marca`)
await m.getByRole('heading', { name: 'Logo e capa' }).waitFor()

await m.getByLabel('Arquivo da logo').setInputFiles(join(dir, 'logo.png'))
await m.getByText('Logo atualizada').waitFor()
await m.getByLabel('Arquivo da capa').setInputFiles(join(dir, 'capa.png'))
await m.getByText('Capa atualizada').waitFor()
check('logo e capa enviadas', (await m.getByAltText('Logo atual').count()) === 1 && (await m.getByAltText('Capa atual').count()) === 1)

await m.getByLabel('CNPJ').fill('11.222.333/0001-82')
await m.getByRole('button', { name: 'Salvar marca' }).click()
await m.getByText('CNPJ inválido. Confira os números.').waitFor()
check('CNPJ inválido é recusado', true)

await m.getByRole('button', { name: 'Vermelho' }).click()
await m.getByLabel('Razão social').fill('Pizzaria Demo Alimentos LTDA')
await m.getByLabel('CNPJ').fill('11222333000181')
await m.getByLabel('Instagram').fill('@pizzariademo')
await m.getByLabel('WhatsApp da loja').fill('(79) 99988-7766')
await m.getByRole('button', { name: 'Salvar marca' }).click()
await m.getByText('Marca atualizada').waitFor()
check('dados salvos e CNPJ formatado', (await m.getByLabel('CNPJ').inputValue()) === '11.222.333/0001-81')
await m.screenshot({ path: `${out}/s12-1-painel-marca.png`, fullPage: true })

// 2. Cardápio mostra capa, logo, contatos, destaques e rodapé com CNPJ
const shop = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 })
const p = await shop.newPage()
await p.goto(HOST + '/')
await p.getByRole('heading', { level: 1 }).waitFor()
await p.getByAltText(/^Logo /).waitFor()
check('logo no topo do cardápio', true)
const contact = p.getByRole('list', { name: 'Contato' })
const wa = await contact.getByRole('link', { name: 'WhatsApp' }).getAttribute('href')
check('botão WhatsApp usa o número da loja', wa?.startsWith('https://wa.me/5579999887766'), wa ?? '')
check('Instagram no topo', (await contact.getByRole('link', { name: '@pizzariademo' }).getAttribute('href')) === 'https://instagram.com/pizzariademo')
const brand = await p.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--brand-600').trim())
check('cor da marca aplicada', brand === '#b91c1c', brand)
const footer = await p.locator('footer address').textContent()
check('rodapé com razão social e CNPJ', footer.includes('Pizzaria Demo Alimentos LTDA') && footer.includes('CNPJ 11.222.333/0001-81'))
await p.screenshot({ path: `${out}/s12-2-cardapio-topo.png` })
await p.screenshot({ path: `${out}/s12-3-cardapio-inteiro.png`, fullPage: true })

// 3. Outro restaurante não herda nada
const other = await shop.newPage()
await other.goto(OTHER_TENANT + '/')
await other.getByRole('heading', { level: 1 }).waitFor()
check('outro restaurante sem a logo/CNPJ deste', (await other.getByAltText(/^Logo /).count()) === 0 && !(await other.locator('footer').textContent()).includes('11.222.333'))

await browser.close()
done()

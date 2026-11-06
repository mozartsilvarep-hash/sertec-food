import { launch, reporter, SHOTS, TENANT, PASSWORDS } from './lib.mjs'

const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()
const ctx = await browser.newContext({ viewport: { width: 1366, height: 820 } })
await ctx.addInitScript(() => { window.print = () => {} })
const m = await ctx.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)

// 1. Estoque: controla o refrigerante com saldo 3, entrada de 2.
await m.goto(`${HOST}/painel/estoque`)
await m.getByRole('heading', { name: 'Estoque' }).waitFor()
await m.getByRole('button', { name: 'Todos' }).click()
const row = m.locator('li').filter({ hasText: 'Refrigerante lata' }).first()
await row.waitFor()
if (await row.getByRole('button', { name: 'Controlar estoque' }).isVisible()) {
  await row.getByRole('button', { name: 'Controlar estoque' }).click()
  const setup = m.getByRole('dialog', { name: /Estoque de Refrigerante lata/ })
  await setup.getByLabel('Quantidade agora').fill('3')
  await setup.getByLabel(/Avisar quando chegar a/).fill('1')
  await setup.getByRole('button', { name: 'Salvar' }).click()
  await setup.waitFor({ state: 'hidden' })
} else {
  await row.getByRole('button', { name: 'Ajustar' }).click()
  const fix = m.getByRole('dialog', { name: /Ajustar Refrigerante lata/ })
  await fix.getByText('Contagem', { exact: true }).click()
  await fix.getByLabel('Quantidade contada').fill('3')
  await fix.getByRole('button', { name: 'Salvar ajuste' }).click()
  await fix.waitFor({ state: 'hidden' })
}
await row.getByLabel('Saldo 3').waitFor()
check('controle de estoque ligado com saldo 3', true)
await row.getByRole('button', { name: 'Ajustar' }).click()
const adj = m.getByRole('dialog', { name: /Ajustar Refrigerante lata/ })
await adj.getByLabel('Quantidade', { exact: true }).fill('2')
await adj.getByLabel('Motivo (opcional)').fill('Compra')
await adj.getByRole('button', { name: 'Salvar ajuste' }).click()
await row.getByLabel('Saldo 5').waitFor()
check('entrada soma ao saldo', true)
await m.screenshot({ path: `${out}/s16-1-estoque.png` })

// 2. Caixa aberto (a conta fecha no caixa).
await m.goto(`${HOST}/painel/caixa`)
await m.getByRole('heading', { name: 'O caixa está fechado' }).or(m.getByText(/Dinheiro na gaveta/)).first().waitFor()
if (await m.getByRole('heading', { name: 'O caixa está fechado' }).isVisible()) {
  await m.getByLabel('Troco inicial na gaveta (R$)').fill('100,00')
  await m.getByRole('button', { name: 'Abrir caixa' }).click()
  await m.getByText(/Dinheiro na gaveta/).waitFor()
}

// 3. Mesas: abre a Mesa 9 para 2 pessoas.
await m.goto(`${HOST}/painel/mesas`)
await m.getByRole('heading', { name: 'Mesas e comandas' }).waitFor()
const openForm = m.getByRole('form', { name: 'Abrir mesa ou comanda' })
await openForm.getByLabel('Mesa ou comanda').fill('Mesa 9')
await openForm.getByRole('button', { name: 'Adicionar pessoa' }).click()
await openForm.getByRole('button', { name: 'Abrir', exact: true }).click()
const sheet = m.getByRole('dialog', { name: 'Mesa 9' })
await sheet.getByText('Sem itens ainda').waitFor()
check('mesa aberta com 2 pessoas', (await sheet.textContent()).includes('Pessoas: 2'))
await sheet.getByRole('button', { name: 'Lançar itens' }).click()

// 4. PDV na mesa: 2 refrigerantes, lança e manda para a cozinha.
await m.getByRole('heading', { name: 'PDV' }).waitFor()
const products = m.getByRole('list', { name: 'Lista de produtos' })
await products.getByRole('button', { name: /Refrigerante lata/ }).waitFor()
check('PDV mostra a mesa', await m.getByText('Mesa 9').first().isVisible())
await products.getByRole('button', { name: /Refrigerante lata/ }).click()
await products.getByRole('button', { name: /Refrigerante lata/ }).click()
await m.getByRole('button', { name: 'Lançar e cozinha' }).click()
const kitchen = m.getByRole('article', { name: 'Pedido da cozinha' })
await kitchen.waitFor()
const kt = (await kitchen.textContent()).replace(/\s+/g, ' ')
check('pedido da cozinha com a mesa e o item', kt.includes('Mesa 9') && /2× Refrigerante lata/.test(kt) && !kt.includes('R$'), kt.slice(0, 80))
await m.screenshot({ path: `${out}/s16-2-cozinha.png` })
await m.getByRole('button', { name: '‹ Voltar' }).click()

// 5. Fechar conta: taxa de serviço e divisão por pessoa.
const onTab = m.getByRole('list', { name: 'Itens já na conta' })
await onTab.getByText(/2× Refrigerante lata/).waitFor()
const cents = (t) => Math.round(Number(t.replace(/[^\d,]/g, '').replace(',', '.')) * 100)
const brl = (c) => (c / 100).toFixed(2).replace('.', ',')
const sub = cents((await onTab.locator('li span.tabular-nums').first().textContent()))
await m.keyboard.press('F4')
const pay = m.getByRole('form', { name: 'Cobrança' })
await pay.getByRole('heading', { name: 'Fechar Mesa 9' }).waitFor()
const pt = (await pay.textContent()).replace(/\s+/g, ' ')
const expected = sub + Math.round(sub * 0.1)
check('total com 10% de serviço e divisão por 2', pt.includes(brl(expected)) && pt.includes(brl(Math.round(expected / 2))), pt.slice(0, 120))
await pay.getByRole('button', { name: 'Pix' }).click()
await m.screenshot({ path: `${out}/s16-3-fechar.png` })
await pay.getByRole('button', { name: 'Confirmar e fechar a conta' }).click()
const ok = m.getByRole('dialog', { name: 'Venda concluída' })
await ok.getByText(/Mesa 9 fechada/).waitFor()
check('conta fechada vira venda', true)
await ok.getByRole('link', { name: 'Imprimir recibo' }).click()
const receipt = m.getByRole('article', { name: 'Recibo' })
await receipt.waitFor()
check('recibo mostra a mesa', (await receipt.textContent()).includes('Mesa 9'))

// 6. Estoque baixou 2.
await m.goto(`${HOST}/painel/estoque`)
await m.locator('li').filter({ hasText: 'Refrigerante lata' }).first().getByLabel('Saldo 3').waitFor()
check('venda na mesa baixou o estoque', true)

// 7. Celular: lista de mesas sem rolagem lateral.
const phone = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await phone.newPage()
await p.goto(`${HOST}/painel/login`)
await p.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await p.getByLabel('Senha').fill(PASSWORDS.owner)
await p.getByRole('button', { name: 'Entrar' }).click()
await p.waitForURL(`${HOST}/painel/`)
for (const path of ['mesas', 'estoque']) {
  await p.goto(`${HOST}/painel/${path}`)
  await p.locator('main h1').waitFor()
  await p.waitForLoadState('networkidle')
  const moved = await p.evaluate(() => { window.scrollTo(400, 0); return window.scrollX })
  check(`${path} no celular sem rolagem lateral`, moved === 0)
  await p.screenshot({ path: `${out}/s16-4-${path}-celular.png`, fullPage: true })
}

await browser.close()
done()

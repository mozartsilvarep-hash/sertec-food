import { launch, reporter, SHOTS, TENANT, PASSWORDS } from './lib.mjs'

const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()
const ctx = await browser.newContext({ viewport: { width: 1366, height: 820 } })
const m = await ctx.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)

// 0. Código e favorito no produto
await m.goto(`${HOST}/painel/cardapio`)
await m.getByRole('link', { name: 'Refrigerante lata' }).first().click()
await m.getByLabel('Código').fill('COCA')
const fav = m.getByRole('switch', { name: 'Botão grande na venda rápida' })
if (!(await fav.isChecked())) await m.getByText('Botão grande na venda rápida').click()
await m.getByRole('button', { name: /^Salvar/ }).first().click()
await m.getByText('Produto salvo').waitFor()
check('código e favorito salvos no produto', true)

// 1. Caixa aberto e PDV
await m.goto(`${HOST}/painel/caixa`)
await m.getByRole('heading', { name: 'O caixa está fechado' }).or(m.getByText(/Dinheiro na gaveta/)).first().waitFor()
if (await m.getByRole('heading', { name: 'O caixa está fechado' }).isVisible()) {
  await m.getByLabel('Troco inicial na gaveta (R$)').fill('100,00')
  await m.getByRole('button', { name: 'Abrir caixa' }).click()
  await m.getByText(/Dinheiro na gaveta/).waitFor()
}
await m.getByRole('link', { name: 'Abrir PDV' }).click()
await m.getByRole('heading', { name: 'PDV' }).waitFor()
const products = m.getByRole('list', { name: 'Lista de produtos' })
await products.getByRole('button', { name: /Refrigerante lata/ }).waitFor()
check('favorito aparece na venda rápida', true)

// 2. Código no teclado (Enter) duas vezes
const search = m.getByLabel('Buscar por código ou nome')
await search.fill('coca')
await search.press('Enter')
await search.fill('COCA')
await search.press('Enter')
const ticket = m.getByRole('list', { name: 'Itens da venda' })
const tt = (await ticket.textContent()).replace(/\s+/g, ' ')
check('código lança o produto (2×)', /Refrigerante lata.*−\s*2\s*\+/.test(tt), tt.slice(0, 60))

// 3. Pizza com tamanho e sabor
await m.keyboard.press('F2')
await search.fill('pizza')
await products.getByRole('button', { name: /Pizza tradicional/ }).click()
const sheet = m.getByRole('dialog', { name: 'Pizza tradicional' })
await sheet.getByText('Grande', { exact: false }).first().click()
await sheet.getByText('Calabresa').click()
await sheet.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await ticket.getByText('Pizza tradicional').waitFor()
check('pizza com sabor entra na venda', (await ticket.textContent()).includes('Calabresa'))

// 4. Avulso, cliente, desconto
await m.getByRole('button', { name: 'Avulso' }).click()
await m.getByRole('form', { name: 'Item avulso' }).getByLabel('Descrição').fill('Vela')
await m.getByRole('form', { name: 'Item avulso' }).getByLabel('Valor (R$)').fill('3,00')
await m.getByRole('button', { name: 'Lançar' }).click()
await m.getByText('Cliente (opcional)').click()
await m.getByLabel('Celular do cliente').fill('(79) 98888-1212')
await m.getByLabel('Nome do cliente').fill('Bruna')
await m.getByText('Desconto e taxa de serviço').click()
await m.getByLabel('Desconto', { exact: true }).fill('5')
await m.screenshot({ path: `${out}/s14-1-pdv.png` })

// 5. Cobrar: Pix + dinheiro com troco
await m.keyboard.press('F4')
const pay = m.getByRole('form', { name: 'Cobrança' })
await pay.waitFor()
const total = (await pay.locator('p.text-3xl').textContent()).trim()
await pay.getByRole('button', { name: 'Pix' }).click()
const pixInput = pay.getByLabel('Valor em Pix')
await pixInput.fill('20,00')
await pay.getByRole('button', { name: 'Dinheiro' }).click()
await pay.getByLabel('Dinheiro recebido (R$)').fill('100')
check('mostra o troco antes de confirmar', await pay.getByText(/^Troco: R\$/).isVisible())
await m.screenshot({ path: `${out}/s14-2-cobrar.png` })
await pay.getByRole('button', { name: 'Confirmar venda' }).click()
const ok = m.getByRole('dialog', { name: 'Venda concluída' })
await ok.getByText(/Venda nº \d+/).waitFor()
check('venda concluída com troco', (await ok.textContent()).includes('Troco'), `total ${total}`)
await m.screenshot({ path: `${out}/s14-3-concluida.png` })

// 6. Recibo
await ok.getByRole('link', { name: 'Imprimir recibo' }).click()
await m.evaluate(() => { window.print = () => {} })
const receipt = m.getByRole('article', { name: 'Recibo' })
await receipt.waitFor()
const rt = await receipt.textContent()
check('recibo com formas, troco, atendente e aviso não fiscal', rt.includes('Pix') && rt.includes('Dinheiro') && rt.includes('Troco') && rt.includes('Atendente') && rt.includes('Não é documento fiscal'))
await m.screenshot({ path: `${out}/s14-4-recibo.png` })

// 7. Histórico e relatório
await m.goto(`${HOST}/painel/relatorios`)
await m.getByRole('heading', { name: 'Formas de pagamento' }).waitFor()
check('relatório separa as formas do balcão', (await m.locator('main').textContent()).includes('Balcão · Pix'))

// 8. Celular
const phone = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await phone.newPage()
await p.goto(`${HOST}/painel/login`)
await p.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await p.getByLabel('Senha').fill(PASSWORDS.owner)
await p.getByRole('button', { name: 'Entrar' }).click()
await p.waitForURL(`${HOST}/painel/`)
await p.goto(`${HOST}/painel/pdv`)
await p.getByRole('list', { name: 'Lista de produtos' }).getByRole('button').first().waitFor()
const moved = await p.evaluate(() => { window.scrollTo(400, 0); return window.scrollX })
check('PDV no celular sem rolagem lateral', moved === 0)
await p.screenshot({ path: `${out}/s14-5-celular.png`, fullPage: true })

await browser.close()
done()

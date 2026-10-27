import { launch, reporter, SHOTS, TENANT, PASSWORDS } from './lib.mjs'

const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()

const panel = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)

// 1. Abrir caixa
await m.getByRole('link', { name: 'Caixa' }).click()
await m.getByRole('heading', { name: 'O caixa está fechado' }).or(m.getByText(/Dinheiro na gaveta/)).first().waitFor()
const fechado = await m.getByRole('heading', { name: 'O caixa está fechado' }).isVisible()
if (!fechado) {
  // Caixa de rodada anterior: fecha para começar limpo.
  await m.getByRole('button', { name: 'Fechar caixa' }).click()
  await m.getByLabel('Dinheiro contado (R$)').fill('0')
  await m.getByRole('button', { name: 'Confirmar fechamento' }).click()
  await m.getByRole('heading', { name: 'O caixa está fechado' }).waitFor()
}
await m.getByLabel('Troco inicial na gaveta (R$)').fill('50,00')
await m.getByRole('button', { name: 'Abrir caixa' }).click()
await m.getByText('Dinheiro na gaveta: R$ 50,00').waitFor()
check('caixa aberto com troco inicial', true)

// 2. Venda no PDV com troco (detalhes do PDV no s14)
await m.getByRole('link', { name: 'Abrir PDV' }).click()
const grid = m.getByRole('list', { name: 'Lista de produtos' })
await m.getByLabel('Buscar por código ou nome').fill('refri')
await grid.getByRole('button', { name: /Refrigerante lata/ }).click()
await grid.getByRole('button', { name: /Refrigerante lata/ }).click()
await m.getByRole('button', { name: 'Avulso' }).click()
await m.getByRole('form', { name: 'Item avulso' }).getByLabel('Descrição').fill('Bolo de pote')
await m.getByRole('form', { name: 'Item avulso' }).getByLabel('Valor (R$)').fill('10,00')
await m.getByRole('button', { name: 'Lançar' }).click()
await m.getByRole('button', { name: 'Cobrar (F4)' }).click()
const pay = m.getByRole('form', { name: 'Cobrança' })
const saleText = (await pay.locator('p.text-3xl').textContent()).trim()
const saleCents = Math.round(Number(saleText.replace(/[^\d,]/g, '').replace(',', '.')) * 100)
await pay.getByRole('button', { name: 'Dinheiro' }).click()
await pay.getByLabel('Dinheiro recebido (R$)').fill('50')
const changeCents = 5000 - saleCents
const brl = (c) => `R$ ${(c / 100).toFixed(2).replace('.', ',')}`
check('troco calculado na tela', await pay.getByText(`Troco: ${brl(changeCents)}`).isVisible(), brl(changeCents))
await m.screenshot({ path: `${out}/s10-1-venda.png`, fullPage: true })
await pay.getByRole('button', { name: 'Confirmar venda' }).click()
await m.getByRole('dialog', { name: 'Venda concluída' }).getByText(`Troco: ${brl(changeCents)}`).waitFor()
check('venda registrada com troco', true)
await m.getByRole('button', { name: /Nova venda/ }).click()
await m.getByRole('link', { name: '‹ Caixa' }).click()
await m.getByText(/Dinheiro na gaveta/).waitFor()

// 3. Sangria
await m.getByRole('button', { name: 'Entrada / saída' }).click()
await m.getByLabel('Tipo').selectOption({ label: 'Retirada (sangria)' })
await m.getByLabel('Valor (R$)').fill('20')
await m.getByLabel('Descrição (opcional)').fill('Depósito no banco')
await m.getByRole('button', { name: 'Registrar' }).click()
await m.getByText('Lançamento registrado').waitFor()
const drawer = 5000 + saleCents - 2000
await m.getByText(`Dinheiro na gaveta: ${brl(drawer)}`).waitFor()
check('gaveta = 50 + venda − 20', true, brl(drawer))
const list = await m.getByRole('region', { name: 'Lançamentos do caixa' }).textContent()
check('lançamentos listados', list.includes('Venda no balcão') && list.includes('Retirada (sangria)') && /Venda nº \d+/.test(list))

// 4. Fechar com falta
await m.getByRole('button', { name: 'Fechar caixa' }).click()
await m.getByLabel('Dinheiro contado (R$)').fill(((drawer - 300) / 100).toFixed(2).replace('.', ','))
check('mostra a falta antes de confirmar', await m.getByText('Falta de R$ 3,00').isVisible())
await m.getByRole('button', { name: 'Confirmar fechamento' }).click()
await m.getByText(/Caixa fechado com falta de R\$ 3,00/).waitFor()
check('fechamento registra a diferença', true)
await m.screenshot({ path: `${out}/s10-2-fechado.png`, fullPage: true })

// 5. Relatório do caixa
await m.getByRole('tab', { name: 'Relatório' }).click()
await m.getByRole('heading', { name: 'Fechamentos' }).waitFor()
await m.getByText('Por categoria').waitFor()
await m.getByText(`contado ${brl(drawer - 300)}`).first().waitFor()
const rep = await m.getByRole('region', { name: 'Relatório do caixa' }).textContent()
check('relatório do período com saídas e fechamento', rep.includes('Retirada (sangria)') && rep.includes('Venda no balcão') && rep.includes(`contado ${brl(drawer - 300)}`), rep.replace(/\s+/g, ' ').slice(0, 140))
await m.screenshot({ path: `${out}/s10-3-relatorio-caixa.png`, fullPage: true })

// 6. Relatórios de pedidos
await m.getByRole('link', { name: 'Relatórios' }).click()
await m.getByRole('heading', { name: 'Mais vendidos' }).waitFor()
check('relatórios de pedidos com mais vendidos e pagamentos', await m.getByRole('heading', { name: 'Formas de pagamento' }).isVisible())
await m.screenshot({ path: `${out}/s10-4-relatorios.png`, fullPage: true })

await browser.close()
done()

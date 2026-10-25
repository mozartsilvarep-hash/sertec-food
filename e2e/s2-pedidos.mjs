import { launch, reporter, SHOTS, TENANT, OTHER_TENANT, ADMIN, PASSWORDS, BASE_DOMAIN } from './lib.mjs'

const ownerPw = PASSWORDS.owner
const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()

// 1. Cliente monta o pedido no celular
const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await shop.newPage()
let checkoutCalls = 0
p.on('request', (r) => { if (r.url().endsWith('/api/v1/checkout')) checkoutCalls++ })
await p.goto(HOST + '/')
await p.getByRole('heading', { name: 'Pizzaria Demo', level: 1 }).waitFor()

await p.getByRole('button', { name: /Pizza tradicional/ }).click()
await p.getByRole('dialog').waitFor()
await p.getByText('Grande', { exact: true }).click()
await p.getByLabel(/Calabresa/).check()
await p.getByLabel(/Margherita/).check()
await p.getByLabel('Alguma observação?').fill('Bem assada')
await p.getByRole('button', { name: /^Adicionar · R\$ 55,90$/ }).click()
await p.getByText('Pizza tradicional adicionado').waitFor()
await p.getByRole('button', { name: /Refrigerante lata/ }).click()
await p.getByRole('button', { name: 'Adicionar quantidade' }).click()
await p.getByRole('button', { name: /^Adicionar · R\$ 13,00$/ }).click()
await p.getByText('Refrigerante lata adicionado').waitFor()
const bar = await p.getByRole('link', { name: /Ver carrinho/ }).textContent()
check('barra do carrinho soma 3 itens e R$ 68,90', bar.includes('3 itens') && bar.includes('R$ 68,90'), bar.trim())
await p.screenshot({ path: `${out}/s2-1-cardapio-com-carrinho.png` })

await p.getByRole('link', { name: /Ver carrinho/ }).click()
await p.getByRole('heading', { name: 'Seu carrinho' }).waitFor()
check('carrinho mostra sabores e observação', (await p.locator('main').textContent()).includes('Bem assada'))
await p.screenshot({ path: `${out}/s2-2-carrinho.png` })
await p.getByRole('button', { name: 'Continuar' }).click()

await p.getByRole('heading', { name: 'Finalizar pedido' }).waitFor()
await p.locator('#bairro').selectOption('Ponta Verde')
await p.getByLabel('Rua').fill('Av. Sandoval Arroxelas')
await p.getByLabel('Número').fill('500')
await p.getByLabel('Nome').fill('Maria Souza')
await p.getByLabel('Celular com DDD').fill('(82) 99876-5432')
await p.getByLabel('Dinheiro').check()
await p.getByLabel('Preciso de troco').check()
await p.getByLabel('Troco para (R$)').fill('100')
const btn = await p.getByRole('button', { name: /Fazer pedido/ }).textContent()
check('total com entrega (68,90 + 6,00)', btn.includes('R$ 74,90'), btn.trim())
await p.screenshot({ path: `${out}/s2-3-checkout.png`, fullPage: true })
// Duplo clique: a interface deve gerar um único pedido.
await p.getByRole('button', { name: /Fazer pedido/ }).dblclick()
await p.waitForURL(/\/pedido\/[a-f0-9]{40}$/)
await p.getByRole('heading', { name: 'Aguardando confirmação' }).waitFor()
const trackUrl = p.url()
check('redireciona ao acompanhamento', true, trackUrl.replace(HOST, ''))
await p.screenshot({ path: `${out}/s2-4-acompanhamento.png`, fullPage: true })

// 2. Restaurante aceita e despacha
const panel = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(ownerPw)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)
await m.getByRole('link', { name: 'Pedidos' }).click()
const cards = m.locator('section[aria-label="Novos"] li')
await cards.first().waitFor()
check('duplo clique gerou um único pedido', (await cards.count()) === 1, `${await cards.count()} pedido(s), ${checkoutCalls} chamada(s) ao checkout`)
await m.screenshot({ path: `${out}/s2-5-painel-pedidos.png` })
await cards.first().getByRole('button').click()
await m.getByRole('dialog').waitFor()
const detail = await m.getByRole('dialog').textContent()
check('detalhe traz telefone, endereço, troco e observação', ['(82) 99876-5432', 'Sandoval', 'troco para R$ 100,00', 'Bem assada'].every((t) => detail.includes(t)))
await m.screenshot({ path: `${out}/s2-6-detalhe-pedido.png` })
await m.getByRole('dialog').locator('select').selectOption('30')
for (const action of ['Aceitar pedido', 'Iniciar preparo', 'Marcar como pronto', 'Saiu para entrega']) {
  await m.getByRole('button', { name: action }).click()
  await m.getByText(/Pedido #1: /).last().waitFor()
  await m.waitForTimeout(300)
}
check('restaurante avança até "Saiu para entrega"', (await m.getByRole('dialog').textContent()).includes('Saiu para entrega'))

// 3. Cliente vê o novo estado
await p.reload()
await p.getByRole('heading', { name: 'Saiu para entrega' }).waitFor()
check('cliente vê "Saiu para entrega" e previsão de 30 min', (await p.locator('main').textContent()).includes('cerca de 30 min'))
await p.screenshot({ path: `${out}/s2-7-acompanhamento-rota.png`, fullPage: true })

// 4. Conclui e confere pagamento
await m.getByRole('button', { name: 'Concluir' }).click()
await m.getByText('Pedido #1: Concluído').waitFor()
const paid = await m.evaluate(async () => {
  const list = await (await fetch('/api/v1/merchant/orders?view=finished', { headers: { Accept: 'application/json' } })).json()
  return list.data[0].payment_status
})
check('pagamento na entrega vira "paid" ao concluir', paid === 'paid', paid)

await browser.close()
done()

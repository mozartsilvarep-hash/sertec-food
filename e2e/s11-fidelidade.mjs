import { launch, reporter, SHOTS, TENANT, PASSWORDS } from './lib.mjs'

const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()
const stamp = String(Date.now())
const PHONE = `(79) 9${stamp.slice(-8, -4)}-${stamp.slice(-4)}`

// 1. Dono liga o cartão: 2 selos = R$ 5 de desconto
const panel = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)
await m.goto(`${HOST}/painel/promocoes`)
await m.getByRole('tab', { name: 'Fidelidade' }).click()
const rule = m.getByRole('form', { name: 'Regra do cartão fidelidade' })
if (!(await rule.getByRole('switch', { name: 'Cartão fidelidade ativo' }).isChecked())) await rule.getByText('Cartão fidelidade ativo').click()
await rule.getByLabel('Selos para ganhar o prêmio').fill('2')
await rule.getByLabel('Desconto (R$)').fill('5,00')
await rule.getByRole('button', { name: 'Salvar' }).click()
await rule.getByText(/a cada 2 pedidos, o cliente ganha R\$ 5,00 de desconto/).waitFor()
check('regra salva', true)

// 2. Compra no balcão: dono dá 2 selos pelo celular → 1 prêmio
const box = m.getByRole('region', { name: 'Cartão de um cliente' })
await box.getByLabel('Celular do cliente').fill(PHONE)
await box.getByRole('button', { name: 'Consultar' }).click()
await box.getByLabel('Selos', { exact: true }).fill('2')
await box.getByLabel('Motivo').fill('Compras no balcão')
await box.getByRole('button', { name: 'Dar selos' }).click()
await box.getByText(/1 prêmio para usar/).waitFor()
check('ajuste manual vira prêmio', true)
await m.screenshot({ path: `${out}/s11-1-fidelidade-painel.png`, fullPage: true })

// 3. Cliente usa o prêmio no pedido
const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await shop.newPage()
await p.goto(HOST + '/')
check('cardápio anuncia o cartão', (await p.locator('p', { hasText: 'Cartão fidelidade:' }).textContent()).includes('a cada 2 pedidos'))
await p.getByRole('button', { name: /Pizza tradicional/ }).click()
await p.getByText('Grande', { exact: true }).click()
await p.getByLabel(/Calabresa/).check()
await p.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await p.getByText('Pizza tradicional adicionado').waitFor()
await p.getByRole('link', { name: /Ver carrinho/ }).click()
await p.getByRole('button', { name: 'Continuar' }).click()
await p.getByLabel('Celular com DDD').fill(PHONE)
await p.getByLabel('Cartão fidelidade').waitFor()
check('checkout mostra o prêmio do celular', (await p.getByLabel('Cartão fidelidade').textContent()).includes('Você tem 1 prêmio'))
await p.getByLabel('Nome').fill('Rita Alves')
await p.getByText('Retirar no local', { exact: true }).click()
await p.getByLabel('Dinheiro').check()
const summary = await p.getByRole('region', { name: 'Resumo' }).textContent()
check('resumo desconta o prêmio', summary.includes('Cartão fidelidade') && summary.includes('− R$ 5,00'), summary.replace(/\s+/g, ' '))
await p.screenshot({ path: `${out}/s11-2-checkout-premio.png`, fullPage: true })
await p.getByRole('button', { name: /Fazer pedido/ }).click()
await p.waitForURL(/\/pedido\/[a-f0-9]{40}$/)
await p.getByText(/Pedido nº \d+/).first().waitFor()
const track = await p.locator('main').textContent()
check('pedido com desconto do cartão', track.includes('Cartão fidelidade') && track.includes('− R$ 5,00'))

// 4. Painel: prêmio consumido e no histórico
await box.getByRole('button', { name: 'Consultar' }).click()
await box.getByText('Prêmio usado').first().waitFor()
check('prêmio usado aparece no histórico', (await box.textContent()).includes('0 prêmios'))

await browser.close()
done()

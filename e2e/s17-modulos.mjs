import { launch, reporter, SHOTS, TENANT, ADMIN, PASSWORDS } from './lib.mjs'

const out = SHOTS
const { check, done } = reporter()
const browser = await launch()

// 1. SerTec: preço dos módulos e Pizzaria Demo só com o PDV.
const actx = await browser.newContext({ viewport: { width: 1366, height: 900 } })
const a = await actx.newPage()
await a.goto(`${ADMIN}/login`)
await a.getByLabel('E-mail').fill('admin@sertec.localhost')
await a.getByLabel('Senha').fill(PASSWORDS.admin)
await a.getByRole('button', { name: 'Entrar' }).click()
await a.getByRole('heading', { name: 'Estabelecimentos' }).waitFor()
await a.getByRole('link', { name: 'Cobrança' }).click()
await a.getByRole('tab', { name: 'Módulos e preços' }).click()
const mods = a.getByRole('region', { name: 'Módulos e preços' })
for (const [name, monthly, yearly] of [['Cardápio digital', '49,90', '499,00'], ['PDV e caixa', '79,90', '799,00']]) {
  await mods.locator('div.rounded-\\(--radius-card\\)').filter({ has: a.getByRole('heading', { name, exact: true }) }).getByRole('button', { name: 'Editar preço' }).click()
  const f = a.getByRole('form', { name: `Editar ${name}` })
  await f.getByLabel('Mensal (R$)').fill(monthly)
  await f.getByLabel('Anual (R$)').fill(yearly)
  await f.getByRole('button', { name: 'Salvar módulo' }).click()
  await f.waitFor({ state: 'detached' })
}
check('preços mensal e anual dos módulos salvos', await mods.getByText('R$ 799,00').first().waitFor({ timeout: 8000 }).then(() => true, () => false))
await a.screenshot({ path: `${out}/s17-1-modulos.png` })

await a.getByRole('link', { name: 'Estabelecimentos' }).click()
await a.getByRole('link', { name: 'Pizzaria Demo' }).first().click()
const bill = a.getByRole('form', { name: 'Mensalidade do estabelecimento' })
await bill.waitFor()
const setBox = async (label, on) => {
  const box = bill.getByRole('checkbox', { name: label, exact: true })
  if ((await box.isChecked()) !== on) await box.click()
}
await setBox('Cardápio digital', false)
await setBox('PDV e caixa', true)
await setBox('Mesas e comandas', false)
await setBox('Estoque', false)
await bill.getByLabel('Ciclo').selectOption({ label: 'Anual' })
await bill.getByLabel('Valor próprio (R$)').fill('')
const bt = (await bill.textContent()).replace(/\s+/g, ' ')
check('total anual = só o PDV', bt.includes('R$ 799,00'), bt.slice(-140))
await a.screenshot({ path: `${out}/s17-2-mensalidade.png` })
await bill.getByRole('button', { name: 'Salvar mensalidade' }).click()
await a.getByText('Mensalidade atualizada').waitFor()

// 2. Painel do restaurante: só o que foi contratado.
const mctx = await browser.newContext({ viewport: { width: 1366, height: 820 } })
const m = await mctx.newPage()
await m.goto(`${TENANT}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${TENANT}/painel/`)
const nav = m.getByRole('navigation').first()
await nav.getByRole('link', { name: 'PDV' }).waitFor()
check('menu mostra o PDV e esconde mesas, estoque e entrega',
  !(await nav.getByRole('link', { name: 'Mesas e comandas' }).count()) && !(await nav.getByRole('link', { name: 'Estoque' }).count()) && !(await nav.getByRole('link', { name: 'Entrega' }).count()))
await m.goto(`${TENANT}/painel/mesas`)
await m.getByText('Mesas e comandas não está no seu plano').waitFor()
check('endereço de módulo não contratado explica e não abre', true)
await m.screenshot({ path: `${out}/s17-3-bloqueado.png` })
await m.goto(`${TENANT}/painel/assinatura`)
const subs = m.getByRole('list', { name: 'Módulos contratados' })
await subs.waitFor()
check('assinatura lista o módulo contratado', (await subs.textContent()).includes('PDV e caixa') && !(await subs.textContent()).includes('Cardápio digital'))

// PDV continua vendendo com produto de opções (sem o cardápio online).
await m.goto(`${TENANT}/painel/pdv`)
await m.getByRole('list', { name: 'Lista de produtos' }).getByRole('button', { name: /Pizza tradicional/ }).click()
const sheet = m.getByRole('dialog', { name: 'Pizza tradicional' })
await sheet.getByText('Calabresa').waitFor()
check('PDV abre produto com sabores sem o cardápio online', true)

// 3. Cardápio online fechado para o público.
const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
const s = await shop.newPage()
await s.goto(TENANT + '/')
await s.getByText('Cardápio online indisponível').waitFor()
check('cardápio online mostra que não recebe pedidos pelo site', true)
await s.screenshot({ path: `${out}/s17-4-loja.png` })

// 4. Volta a Pizzaria Demo com todos os módulos (para os próximos cenários).
await a.reload()
await bill.waitFor()
for (const l of ['Cardápio digital', 'PDV e caixa', 'Mesas e comandas', 'Estoque']) await setBox(l, true)
await bill.getByLabel('Ciclo').selectOption({ label: 'Mensal' })
await bill.getByLabel('Valor próprio (R$)').fill('0')
await bill.getByRole('button', { name: 'Salvar mensalidade' }).click()
await a.getByText('Mensalidade atualizada').waitFor()
await s.reload()
await s.waitForLoadState('networkidle')
check('com o módulo de volta, o cardápio abre', (await s.getByText('Cardápio online indisponível').count()) === 0 && (await s.getByText('Pizza tradicional').count()) > 0)

await browser.close()
done()

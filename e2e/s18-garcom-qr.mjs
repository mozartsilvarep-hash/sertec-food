import { launch, reporter, SHOTS, TENANT, PASSWORDS } from './lib.mjs'

const out = SHOTS
const { check, done } = reporter()
const browser = await launch()

async function login(ctx, email, password) {
  const p = await ctx.newPage()
  await p.goto(`${TENANT}/painel/login`)
  await p.getByLabel('E-mail').fill(email)
  await p.getByLabel('Senha').fill(password)
  await p.getByRole('button', { name: 'Entrar' }).click()
  await p.waitForURL((u) => !u.pathname.endsWith('/login'))
  return p
}

// 1. Gerente cadastra as mesas e imprime os QR.
const desk = await browser.newContext({ viewport: { width: 1366, height: 900 } })
await desk.addInitScript(() => { window.print = () => {} })
const m = await login(desk, 'dono@pizzaria.localhost', PASSWORDS.owner)
await m.goto(`${TENANT}/painel/mesas`)
// Unidade do atendente (ele é o garçom no teste).
await m.getByLabel('Unidade').selectOption({ label: 'Unidade Ponta Verde' })
const create = m.getByRole('form', { name: 'Criar mesas' })
await create.getByLabel('De').fill('1')
await create.getByLabel('Até').fill('3')
await create.getByRole('button', { name: 'Criar mesas' }).click()
await m.getByRole('region', { name: 'Mesas e QR Codes' }).getByText('Mesa 3', { exact: true }).waitFor()
check('mesas 1 a 3 criadas', true)
await m.getByRole('link', { name: 'Imprimir todos os QR' }).click()
const sheet = m.getByRole('list', { name: 'QR Codes das mesas' })
await sheet.waitFor()
check('folha de QR com as 3 mesas', (await sheet.locator('svg').count()) >= 3)
await m.screenshot({ path: `${out}/s18-1-qr.png` })
const branchQs = new URL(m.url()).searchParams.get('branch') ?? ''
const tables = await m.evaluate(async (b) => (await (await fetch(`/api/v1/merchant/cash/dining-tables?branch=${b}`, { credentials: 'include', headers: { Accept: 'application/json' } })).json()).data, branchQs)
const qr = tables.find((t) => t.label === 'Mesa 1').qr_url.replace(/^https:\/\/[^/]+/, TENANT)

// 2. Cliente escaneia, escolhe e envia.
const phone = await browser.newContext({ viewport: { width: 390, height: 844 } })
const c = await phone.newPage()
await c.goto(qr)
await c.getByRole('region', { name: 'Pedido na mesa' }).getByText('Mesa 1').waitFor()
check('QR abre o cardápio no modo mesa', true)
await c.getByRole('button', { name: /Refrigerante lata/ }).first().click()
await c.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await c.getByText('Refrigerante lata adicionado').waitFor()
await c.goto(`${TENANT}/carrinho`)
await c.getByLabel('Seu nome (opcional)').fill('Rita')
await c.getByRole('button', { name: 'Enviar pedido para a Mesa 1' }).click()
await c.getByRole('heading', { name: 'Aguardando o garçom' }).waitFor()
check('cliente vê que aguarda o garçom', true)
await c.screenshot({ path: `${out}/s18-2-cliente.png` })

// 3. Garçom confirma no celular.
const waiterCtx = await browser.newContext({ viewport: { width: 390, height: 844 } })
const w = await login(waiterCtx, 'atendente@pizzaria.localhost', PASSWORDS.attendant)
await w.goto(`${TENANT}/painel/garcom`)
const waiting = w.getByRole('region', { name: 'Pedidos do QR aguardando' })
await waiting.getByText('Mesa 1').first().waitFor()
check('garçom vê o pedido do QR', (await waiting.textContent()).includes('Refrigerante lata'))
await w.screenshot({ path: `${out}/s18-3-garcom.png` })
await waiting.getByRole('button', { name: 'Confirmar e mandar à cozinha' }).first().click()
await w.getByText(/Mesa 1: confirmado e enviado à cozinha/).waitFor()
await c.getByRole('heading', { name: 'Pedido confirmado!' }).waitFor({ timeout: 15000 })
check('cliente vê o pedido confirmado', true)

// 4. Cliente pede a conta; garçom atende; caixa vê "Conta pedida".
await c.getByRole('button', { name: /Pedir mais/ }).click()
await c.getByRole('button', { name: 'Pedir a conta' }).click()
await c.getByText('Pedimos a conta').waitFor()
await w.reload()
await waiting.getByText('Pediu a conta').waitFor()
await waiting.getByRole('button', { name: 'Atendido' }).first().click()
await w.getByText(/Mesa 1: atendido/).waitFor()
const mesa1 = w.getByRole('region', { name: 'Mesas' }).getByRole('button', { name: /Mesa 1/ })
await mesa1.getByText('CONTA').waitFor()
check('mesa marcada com conta pedida', true)

// 5. Garçom lança direto pelo celular.
await mesa1.click()
const tab = w.getByRole('dialog', { name: 'Mesa 1' })
await tab.getByRole('button', { name: 'Adicionar itens' }).click()
await tab.getByRole('list', { name: 'Produtos' }).getByRole('button', { name: /Refrigerante lata/ }).click()
await tab.getByRole('button', { name: /Lançar e mandar à cozinha/ }).click()
await w.getByText(/Enviado à cozinha/).waitFor()
check('garçom lança e manda à cozinha pelo celular', (await tab.textContent()).includes('envio 2'))
const moved = await w.evaluate(() => { window.scrollTo(400, 0); return window.scrollX })
check('tela do garçom sem rolagem lateral', moved === 0)
await w.screenshot({ path: `${out}/s18-4-mesa.png` })

await m.goto(`${TENANT}/painel/mesas`)
await m.getByLabel('Unidade').selectOption({ label: 'Unidade Ponta Verde' })
await m.getByText('Conta pedida').first().waitFor()
check('caixa vê "Conta pedida" na lista', true)

// Limpa para rodar de novo: cancela a conta da Mesa 1.
await m.getByRole('button', { name: /Mesa 1/ }).first().click()
const box = m.getByRole('dialog', { name: 'Mesa 1' })
await box.getByRole('button', { name: 'Cancelar a conta inteira' }).click()
await box.getByLabel('Motivo').fill('Teste E2E')
await box.getByRole('button', { name: 'Cancelar conta' }).click()
await box.waitFor({ state: 'hidden' })

await browser.close()
done()

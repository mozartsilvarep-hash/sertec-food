import { launch, reporter, SHOTS, TENANT, ADMIN, PASSWORDS } from './lib.mjs'

const out = SHOTS
const MP = 'http://127.0.0.1:9911'
// Conta de teste do Mercado Pago falso (a mesma aceita pelo fake-mercadopago.mjs).
const SERTEC_TOKEN = 'TEST-1111222233334444-102512-e2eloja-0001'
const { check, done } = reporter()
const browser = await launch()

// 1. SerTec conecta a conta que recebe as mensalidades e define o preço do plano
const actx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const a = await actx.newPage()
await a.goto(ADMIN + '/login')
await a.getByLabel('E-mail').fill('admin@sertec.localhost')
await a.getByLabel('Senha').fill(PASSWORDS.admin)
await a.getByRole('button', { name: 'Entrar' }).click()
await a.getByRole('heading', { name: 'Estabelecimentos' }).waitFor()
await a.getByRole('link', { name: 'Cobrança' }).click()
await a.getByRole('heading', { name: 'Cobrança', level: 1 }).waitFor()

await a.getByRole('tab', { name: 'Mercado Pago' }).click()
await a.getByLabel('Access Token de produção').fill(SERTEC_TOKEN)
await a.getByRole('button', { name: /Conectar conta|Atualizar/ }).click()
await a.getByText('Conta Mercado Pago da SerTec conectada.').waitFor()
const mpBox = await a.getByRole('region', { name: 'Mercado Pago da SerTec' }).textContent()
check('conta da SerTec conectada sem mostrar o token', mpBox.includes('Conectada') && !mpBox.includes(SERTEC_TOKEN), mpBox.includes('…0001') ? '…0001' : '')

await a.getByRole('tab', { name: 'Planos' }).click()
await a.getByRole('button', { name: 'Editar' }).first().click()
const planForm = a.getByRole('form', { name: 'Editar plano' })
await planForm.getByLabel('Mensal (R$)').fill('89,90')
await planForm.getByLabel('Trimestral (R$)').fill('249,90')
await planForm.getByRole('button', { name: 'Salvar plano' }).click()
await a.getByText(/Plano salvo/).waitFor()
// A lista recarrega logo depois da mensagem.
const saved = await a.getByRole('region', { name: 'Planos' }).getByText('R$ 249,90').first().waitFor({ timeout: 8000 }).then(() => true, () => false)
check('preço do plano por período salvo', saved)
await a.screenshot({ path: `${out}/s13-1-planos.png`, fullPage: true })

// 2. Mensalidade da Pizzaria Demo: plano mensal e cobrança gerada
await a.getByRole('link', { name: 'Estabelecimentos' }).click()
await a.getByRole('link', { name: 'Pizzaria Demo' }).first().click()
const billingForm = a.getByRole('form', { name: 'Mensalidade do estabelecimento' })
await billingForm.waitFor()
await billingForm.getByLabel('Plano').selectOption({ index: 0 })
await billingForm.getByLabel('Período').selectOption({ label: 'Mensal · R$ 89,90' })
await billingForm.getByLabel('Valor próprio (R$)').fill('')
await billingForm.getByRole('button', { name: 'Salvar mensalidade' }).click()
await a.getByText('Mensalidade atualizada').waitFor()
await billingForm.getByRole('button', { name: 'Gerar cobrança agora' }).click()
await a.getByText('Fatura gerada').waitFor()
const list = await a.getByRole('list', { name: 'Faturas do estabelecimento' }).textContent()
check('fatura em aberto criada', list.includes('R$ 89,90') && list.includes('vence'), list.replace(/\s+/g, ' ').slice(0, 80))
await a.screenshot({ path: `${out}/s13-2-mensalidade.png`, fullPage: true })

// 3. Dono do restaurante paga por Pix no painel
const mctx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await mctx.newPage()
await m.goto(`${TENANT}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${TENANT}/painel/`)
await m.getByRole('link', { name: 'Assinatura' }).click()
await m.getByRole('heading', { name: 'Assinatura' }).waitFor()
const card = m.getByRole('article', { name: 'Fatura de R$ 89,90' })
await card.getByRole('button', { name: 'Pagar com Pix' }).click()
const pix = m.getByRole('region', { name: 'Pix da mensalidade' })
await pix.getByAltText('QR Code do Pix').waitFor()
const code = await pix.getByLabel('Código Pix').inputValue()
check('Pix gerado com QR e copia e cola', code.startsWith('000201'), code.slice(0, 20))
const last = await (await fetch(`${MP}/__last`)).json()
check('Pix criado na conta da SerTec com a referência da fatura', /^sertec-fatura:[0-9a-z]{26}:[0-9a-z]{26}$/.test(last.external_reference) && last.transaction_amount === 89.9, last.external_reference)
await m.screenshot({ path: `${out}/s13-3-pix.png`, fullPage: true })

// Cliente paga no banco: a tela confirma sozinha
await fetch(`${MP}/__set/${last.id}/approved`)
await m.getByText('Pagamento confirmado. Obrigado!').waitFor({ timeout: 30000 })
await m.getByRole('region', { name: 'Histórico' }).or(m.getByRole('table')).first().waitFor()
const hist = await m.locator('main').textContent()
check('fatura aparece paga por Pix e o plano em dia', hist.includes('(Pix)') && hist.includes('Em dia') && hist.includes('Pago até'))
await m.screenshot({ path: `${out}/s13-4-pago.png`, fullPage: true })

// 4. SerTec vê o recebimento
await a.getByRole('link', { name: 'Cobrança' }).click()
await a.getByLabel('Mostrar').selectOption({ label: 'Pagas' })
await a.getByRole('cell', { name: 'Pizzaria Demo' }).first().waitFor()
const paid = await a.getByRole('region', { name: 'Faturas' }).textContent()
check('painel SerTec mostra a fatura paga por Pix', paid.includes('Paga (Pix)'))
await a.screenshot({ path: `${out}/s13-5-recebidas.png`, fullPage: true })

await browser.close()
done()

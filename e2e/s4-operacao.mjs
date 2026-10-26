import { launch, reporter, SHOTS, TENANT, OTHER_TENANT, ADMIN, PASSWORDS, BASE_DOMAIN } from './lib.mjs'
import { promises as fs } from 'node:fs'

const ownerPw = PASSWORDS.owner
const out = SHOTS
const HOST = TENANT
const NAME = `Rita Alves ${String(Date.now()).slice(-4)}`
const { check, done } = reporter()
const browser = await launch()

// Painel aberto em "Unidades" (fora da tela de pedidos) com som ligado
const panel = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(ownerPw)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)
const bar = m.getByRole('region', { name: 'Pedidos ao vivo' })
await bar.waitFor()
await m.getByRole('button', { name: /Ativar som/ }).click()
check('som de alerta ligado', (await m.getByRole('button', { name: /Som ligado/ }).count()) === 1)
await m.waitForTimeout(3500)
const pendingBefore = Number(((await bar.textContent()).match(/(\d+) pedidos? novos?/) ?? [0, 0])[1])

// Cliente faz um pedido pagando na retirada
const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await shop.newPage()
await p.goto(HOST + '/')
await p.getByRole('button', { name: /Refrigerante lata/ }).click()
await p.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await p.getByText('Refrigerante lata adicionado').waitFor()
await p.getByRole('button', { name: /Pizza tradicional/ }).click()
await p.getByText('Grande', { exact: true }).click()
await p.getByLabel(/Calabresa/).check()
await p.getByRole('button', { name: /^Adicionar · R\$/ }).click()
await p.getByText('Pizza tradicional adicionado').waitFor()
await p.getByRole('link', { name: /Ver carrinho/ }).click()
await p.getByRole('button', { name: 'Continuar' }).click()
await p.getByText('Retirar no local', { exact: true }).click()
await p.getByLabel('Nome').fill(NAME)
await p.getByLabel('Celular com DDD').fill('(82) 96666-3333')
await p.getByLabel('Dinheiro').check()
const t0 = Date.now()
await p.getByRole('button', { name: /Fazer pedido/ }).click()
await p.waitForURL(/\/pedido\/[a-f0-9]{40}$/)

// Painel em outra tela recebe o alerta em segundos
await m.waitForFunction((n) => document.title.startsWith(`(${n}) `), pendingBefore + 1, { timeout: 10000 })
const secs = ((Date.now() - t0) / 1000).toFixed(1)
check('alerta chega ao painel em poucos segundos (sem recarregar)', Number(secs) <= 6, `${secs}s · título: ${await m.title()}`)
check('faixa ao vivo mostra pedido novo e link', (await bar.textContent()).includes('novo') && (await bar.getByRole('link', { name: 'Ver pedidos' }).count()) === 1)
await m.screenshot({ path: `${out}/s4-1-alerta-ao-vivo.png` })

// Pedidos: aceita e imprime comanda
await bar.getByRole('link', { name: 'Ver pedidos' }).click()
const card = m.locator('section[aria-label="Novos"] li', { hasText: NAME })
await card.waitFor()
await card.getByRole('button').click()
await m.getByRole('dialog').waitFor()
await m.getByRole('button', { name: 'Aceitar pedido' }).click()
await m.getByText(/: Pedido confirmado/).waitFor()
await m.evaluate(() => { window.print = () => { window.__printed = true } })
await m.getByRole('dialog').getByRole('link', { name: 'Imprimir' }).click()
await m.waitForURL(/\/imprimir$/)
const ticket = m.getByRole('article', { name: 'Comanda' })
await ticket.waitFor()
const tt = await ticket.textContent()
check('comanda tem cliente, itens, total e pagamento', tt.includes(NAME) && tt.includes('Pizza tradicional') && tt.includes('Pagamento: Dinheiro') && tt.includes('Total'))
const w = await ticket.evaluate((el) => el.getBoundingClientRect().width)
check('comanda em 80 mm', Math.abs(w - 302) < 6, `${w.toFixed(0)}px`)
check('abre a impressão automaticamente', await m.evaluate(() => window.__printed === true))
await m.screenshot({ path: `${out}/s4-2-comanda.png` })
await m.emulateMedia({ media: 'print' })
check('botões somem na impressão', await m.getByRole('button', { name: 'Imprimir' }).isHidden())
await m.emulateMedia({ media: 'screen' })

// Histórico: busca + resumo + CSV
await m.goto(`${HOST}/painel/historico`)
await m.getByRole('heading', { name: 'Histórico' }).waitFor()
await m.getByPlaceholder('Nº, nome ou celular').fill(NAME)
await m.getByRole('button', { name: 'Filtrar' }).click()
await m.getByRole('cell', { name: NAME }).waitFor()
// A lista sem filtro pode ainda estar na tela: espera o resultado da busca.
const onlyOne = await m.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1, null, { timeout: 8000 }).then(() => true, () => false)
check('histórico encontra o pedido pela busca', onlyOne, String(await m.locator('tbody tr').count()))
check('resumo do período aparece', (await m.getByRole('region', { name: 'Resumo do período' }).textContent()).includes('Faturamento'))
await m.screenshot({ path: `${out}/s4-3-historico.png`, fullPage: true })
const [download] = await Promise.all([m.waitForEvent('download'), m.getByRole('link', { name: 'Exportar CSV' }).click()])
const csv = await fs.readFile(await download.path(), 'utf8')
check('CSV baixado com o pedido', csv.includes(NAME) && csv.startsWith('﻿Número;'), download.suggestedFilename())

// Auditoria
await m.goto(`${HOST}/painel/auditoria`)
await m.getByRole('heading', { name: 'Auditoria' }).waitFor()
await m.getByText('Mudou o status de um pedido').first().waitFor()
const audit = await m.locator('main').textContent()
check('auditoria mostra aceite e exportação', audit.includes('Mudou o status de um pedido') && audit.includes('Exportou pedidos (CSV)'))
await m.screenshot({ path: `${out}/s4-4-auditoria.png` })

// Cliente: acompanhamento atualiza sozinho e tem contato
await p.getByRole('heading', { name: 'Pedido confirmado' }).waitFor({ timeout: 15000 })
check('acompanhamento do cliente atualiza sem recarregar', true)
await p.screenshot({ path: `${out}/s4-5-acompanhamento.png`, fullPage: true })

await browser.close()
done()

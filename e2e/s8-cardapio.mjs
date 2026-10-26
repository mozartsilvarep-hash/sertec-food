import { launch, reporter, SHOTS, TENANT, PASSWORDS } from './lib.mjs'

const out = SHOTS
const HOST = TENANT
const { check, done } = reporter()
const browser = await launch()
const stamp = String(Date.now())
const PHONE = `(79) 9${stamp.slice(-8, -4)}-${stamp.slice(-4)}`

// 1. Painel: produto em dia específico, combo e "compre 1, leve outro"
const panel = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const m = await panel.newPage()
await m.goto(`${HOST}/painel/login`)
await m.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await m.getByLabel('Senha').fill(PASSWORDS.owner)
await m.getByRole('button', { name: 'Entrar' }).click()
await m.waitForURL(`${HOST}/painel/`)

// Dia que NÃO é hoje (fuso de Maceió/São Paulo é o mesmo do navegador de teste? usa o do servidor via rótulo).
const today = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/Maceio' })).getDay()
const otherDay = (today + 3) % 7
const DAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb']

await m.goto(`${HOST}/painel/cardapio/produtos/novo`)
await m.getByLabel('Nome', { exact: true }).fill('Bolo de rolo')
await m.getByLabel('Preço (R$)').fill('12,00')
await m.getByLabel('Sobremesas').check()
await m.getByRole('group', { name: 'Dias da semana' }).getByRole('button', { name: DAYS[otherDay], exact: true }).click()
await m.getByRole('button', { name: 'Criar produto' }).click()
await m.getByText('Produto criado').waitFor()
check('produto com dia específico salvo', true)

await m.goto(`${HOST}/painel/cardapio/produtos/novo`)
await m.getByLabel('Nome', { exact: true }).fill('Combo Lanche')
await m.getByLabel('Combo', { exact: true }).check()
await m.getByLabel('Parte 1', { exact: true }).fill('Escolha 2 refrigerantes')
await m.getByLabel('Quantidade', { exact: true }).fill('2')
await m.getByLabel('Itens do combo').getByLabel('Refrigerante lata').check()
await m.getByLabel('Preço (R$)').fill('10,00')
await m.getByLabel('Bebidas').check()
await m.screenshot({ path: `${out}/s8-1-combo.png`, fullPage: true })
await m.getByRole('button', { name: 'Criar produto' }).click()
await m.getByText('Produto criado').waitFor()
check('combo criado com itens do cardápio', true)

// Produto próprio do cenário para a promoção (não depende de promoções de outros cenários).
await m.goto(`${HOST}/painel/cardapio/produtos/novo`)
await m.getByLabel('Nome', { exact: true }).fill('Brigadeiro gourmet')
await m.getByLabel('Preço (R$)').fill('4,00')
await m.getByLabel('Sobremesas').check()
await m.getByRole('button', { name: 'Criar produto' }).click()
await m.getByText('Produto criado').waitFor()

await m.goto(`${HOST}/painel/promocoes`)
await m.getByRole('button', { name: 'Nova promoção' }).click()
await m.getByLabel('Produto').selectOption({ label: 'Brigadeiro gourmet' })
await m.getByLabel('Leve mais, pague menos').check()
await m.getByRole('button', { name: 'Compre 1, leve outro' }).click()
await m.getByRole('button', { name: 'Criar promoção' }).click()
await m.getByText(/Promoção criada: Brigadeiro gourmet/).waitFor()
check('promoção "leve 2, pague 1" criada', (await m.locator('main').textContent()).includes('Leve 2, pague 1'))

// 2. Cardápio do cliente
const shop = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await shop.newPage()
await p.goto(HOST + '/')
const bolo = p.getByRole('button', { name: /Bolo de rolo/ })
check('produto fora do dia aparece com aviso e bloqueado', (await bolo.textContent()).includes(`Só ${DAYS[otherDay].toLowerCase()}.`) && await bolo.isDisabled(), (await bolo.textContent()).trim())
const combo = p.getByRole('button', { name: /Combo Lanche/ })
check('combo com selo e preço "de" (2 × 6,50)', (await combo.textContent()).includes('Combo') && (await combo.textContent()).includes('R$ 13,00'))
check('selo "Leve 2, pague 1" no brigadeiro', (await p.getByRole('button', { name: /Brigadeiro gourmet/ }).textContent()).includes('Leve 2, pague 1'))
await p.screenshot({ path: `${out}/s8-2-cardapio.png`, fullPage: true })

await combo.click()
await p.getByRole('dialog').waitFor()
await p.getByRole('button', { name: 'Adicionar Refrigerante lata' }).click()
await p.getByRole('button', { name: 'Adicionar Refrigerante lata' }).click()
await p.getByRole('button', { name: /^Adicionar · R\$ 10,00$/ }).click()
await p.getByText('Combo Lanche adicionado').waitFor()
await p.getByRole('button', { name: /Brigadeiro gourmet/ }).click()
await p.getByRole('button', { name: 'Adicionar quantidade' }).click()
await p.getByRole('button', { name: /^Adicionar · R\$ 8,00$/ }).click()
await p.getByText('Brigadeiro gourmet adicionado').waitFor()
await p.getByRole('link', { name: /Ver carrinho/ }).click()
await p.getByRole('heading', { name: 'Seu carrinho' }).waitFor()
const cartText = await p.locator('main').textContent()
check('carrinho: 1 brigadeiro de graça', cartText.includes('Leve 2, pague 1 · Brigadeiro gourmet') && cartText.includes('− R$ 4,00') && cartText.includes('R$ 14,00'), cartText.replace(/\s+/g, ' ').slice(0, 200))
await p.screenshot({ path: `${out}/s8-3-carrinho.png`, fullPage: true })
await p.getByRole('button', { name: 'Continuar' }).click()
await p.getByLabel('Celular com DDD').fill(PHONE)
await p.getByLabel('Nome').fill('Carla Dias')
await p.getByText('Retirar no local', { exact: true }).click()
await p.getByLabel('Dinheiro').check()
check('checkout com total já descontado', (await p.getByRole('button', { name: /Fazer pedido/ }).textContent()).includes('R$ 14,00'))
await p.getByRole('button', { name: /Fazer pedido/ }).click()
await p.waitForURL(/\/pedido\/[a-f0-9]{40}$/)
await p.getByText(/Pedido nº \d+/).first().waitFor()
const track = await p.locator('main').textContent()
check('acompanhamento mostra combo e desconto com "−"', track.includes('Combo Lanche') && track.includes('− R$ 4,00'))
const number = track.match(/Pedido nº (\d+)/)[1]

// 3. Painel: chamar o cliente no WhatsApp
await m.goto(`${HOST}/painel/pedidos`)
await m.getByText(`#${number}`, { exact: true }).first().click()
const wa = await m.getByRole('link', { name: 'Chamar no WhatsApp' }).getAttribute('href')
const digits = PHONE.replace(/\D/g, '')
check('botão "Chamar no WhatsApp" com o celular e o nº do pedido', wa.startsWith(`https://wa.me/55${digits}?text=`) && decodeURIComponent(wa).includes(`pedido nº ${number}`), decodeURIComponent(wa).slice(0, 100))
await m.screenshot({ path: `${out}/s8-4-whatsapp-cliente.png`, fullPage: true })

await browser.close()
done()

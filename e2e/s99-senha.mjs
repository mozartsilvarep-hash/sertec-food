import { readFileSync } from 'node:fs'
import { launch, reporter, SHOTS, TENANT, ADMIN } from './lib.mjs'

// "Esqueci a senha" nos dois painéis. Localmente o e-mail vai para o log
// (MAIL_MAILER=log); o cenário lê o link de lá. Rode por último: troca a
// senha do proprietário (demo:prepare-e2e restaura).
const out = SHOTS
const LOG = process.env.E2E_MAIL_LOG ?? new URL('../backend/storage/logs/laravel.log', import.meta.url).pathname
const { check, done } = reporter()
const browser = await launch()

// Só olha o que foi escrito depois do pedido (o log guarda e-mails antigos).
const logSize = () => { try { return readFileSync(LOG, 'utf8').length } catch { return 0 } }
async function linkFor(email, pathPart, from) {
  for (let i = 0; i < 40; i++) {
    const log = readFileSync(LOG, 'utf8').slice(from)
    const idx = log.lastIndexOf(`To: ${email}`)
    if (idx >= 0) {
      const m = log.slice(idx).match(new RegExp(`https?://[^\\s"<>]*${pathPart}\\?token=[^\\s"<>&]+&(?:amp;)?email=[^\\s"<>]+`))
      if (m) return m[0].replace(/&amp;/g, '&')
    }
    await new Promise((r) => setTimeout(r, 500))
  }
  return null
}

// 1. Painel do restaurante
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } })
const p = await ctx.newPage()
await p.goto(`${TENANT}/painel/login`)
await p.getByRole('link', { name: 'Esqueci minha senha' }).click()
await p.getByRole('heading', { name: 'Esqueci minha senha' }).waitFor()
await p.getByLabel('E-mail').fill('dono@pizzaria.localhost')
const before = logSize()
await p.getByRole('button', { name: 'Enviar link' }).click()
await p.getByText('Se este e-mail tiver acesso ao painel').waitFor()
check('resposta genérica em português', true)
await p.screenshot({ path: `${out}/s9-1-esqueci.png` })

const link = await linkFor('dono@pizzaria.localhost', '/painel/redefinir-senha', before)
check('link chegou por e-mail apontando para o painel da loja', Boolean(link) && link.includes('pizzaria-demo.'), link?.split('?')[0])
await p.goto(link.replace(/^https?:\/\/[^/]+/, TENANT))
await p.getByRole('heading', { name: 'Criar senha nova' }).waitFor()
await p.getByLabel('Nova senha', { exact: true }).fill('semmaiuscula@1')
await p.getByLabel('Repita a nova senha').fill('semmaiuscula@1')
await p.getByRole('button', { name: 'Salvar senha nova' }).click()
await p.getByText('A nova senha precisa ter ao menos uma letra maiúscula.').waitFor()
check('regra de senha na redefinição', true)
await p.getByLabel('Nova senha', { exact: true }).fill('Pizza@2026')
await p.getByLabel('Repita a nova senha').fill('Pizza@2026')
await p.getByRole('button', { name: 'Salvar senha nova' }).click()
await p.waitForURL(/\/painel\/login/)
await p.getByText('Senha criada. Entre com a senha nova.').waitFor()
await p.getByLabel('E-mail').fill('dono@pizzaria.localhost')
await p.getByLabel('Senha').fill('Pizza@2026')
await p.getByRole('button', { name: 'Entrar' }).click()
const entered = await p.waitForURL(`${TENANT}/painel/`, { timeout: 15000 }).then(() => true, () => false)
check('entra com a senha nova', entered, entered ? '' : (await p.locator('main').textContent()).replace(/\s+/g, ' ').slice(0, 160))

// O mesmo link não vale de novo.
const again = await browser.newContext()
const q = await again.newPage()
await q.goto(link.replace(/^https?:\/\/[^/]+/, TENANT))
await q.getByLabel('Nova senha', { exact: true }).fill('Outra@2026')
await q.getByLabel('Repita a nova senha').fill('Outra@2026')
await q.getByRole('button', { name: 'Salvar senha nova' }).click()
await q.getByText(/Este link expirou ou já foi usado/).waitFor()
check('link usado não funciona de novo', true)

// 2. Painel SerTec
const a = await (await browser.newContext()).newPage()
await a.goto(`${ADMIN}/esqueci-senha`)
await a.getByLabel('E-mail').fill('admin@sertec.localhost')
const beforeAdmin = logSize()
await a.getByRole('button', { name: 'Enviar link' }).click()
await a.getByText('Se este e-mail tiver acesso ao painel').waitFor()
const adminLink = await linkFor('admin@sertec.localhost', '/redefinir-senha', beforeAdmin)
check('equipe SerTec recebe link do painel SerTec', Boolean(adminLink) && !adminLink.includes('/painel/'), adminLink?.split('?')[0])

await browser.close()
done()

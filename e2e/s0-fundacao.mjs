import { launch, reporter, SHOTS, TENANT, OTHER_TENANT, ADMIN, PASSWORDS, BASE_DOMAIN } from './lib.mjs'

const ownerPw = PASSWORDS.owner
const attendantPw = PASSWORDS.attendant
const adminPw = PASSWORDS.admin
const out = SHOTS
const browser = await launch()
const { check, done } = reporter()

async function newPage(viewport = { width: 1280, height: 800 }) {
  const ctx = await browser.newContext({ viewport })
  return { ctx, page: await ctx.newPage() }
}

async function login(page, host, email, password) {
  await page.goto(`http://${host}/painel/login`)
  await page.getByLabel('E-mail').fill(email)
  await page.getByLabel('Senha').fill(password)
  await page.getByRole('button', { name: 'Entrar' }).click()
}

{ // 1. Storefront
  const { ctx, page } = await newPage({ width: 390, height: 844 })
  await page.goto(TENANT + '/')
  await page.getByRole('heading', { name: 'Pizzaria Demo' }).waitFor()
  await page.screenshot({ path: `${out}/1-storefront-mobile.png` })
  check('storefront mostra o tenant do host', true)
  await page.goto(`http://nao-existe.${BASE_DOMAIN}/`)
  await page.getByText('Indisponível no momento').waitFor()
  check('host desconhecido mostra indisponível', true)
  await ctx.close()
}

{ // 2. Login com senha errada (mobile)
  const { ctx, page } = await newPage({ width: 390, height: 844 })
  await login(page, `pizzaria-demo.${BASE_DOMAIN}`, 'dono@pizzaria.localhost', 'errada')
  const alert = await page.getByRole('alert').textContent()
  check('senha errada mostra erro genérico', alert?.includes('E-mail ou senha inválidos'), `"${alert}"`)
  await page.screenshot({ path: `${out}/2-login-erro-mobile.png` })
  await ctx.close()
}

{ // 3. Dono loga, vê as duas unidades; sessão não vale no outro tenant
  const { ctx, page } = await newPage()
  await login(page, `pizzaria-demo.${BASE_DOMAIN}`, 'dono@pizzaria.localhost', ownerPw)
  await page.getByRole('heading', { name: 'Unidades' }).waitFor()
  await page.getByText('Unidade Ponta Verde').waitFor()
  const items = await page.locator('main li').allTextContents()
  check('dono vê Matriz e Ponta Verde', items.length === 2, JSON.stringify(items.map(i => i.split('\n')[0].trim())))
  await page.screenshot({ path: `${out}/3-painel-dono.png` })

  await page.goto(OTHER_TENANT + '/painel/')
  await page.waitForURL('**/painel/login')
  check('sessão da pizzaria não abre o painel do burger', page.url().endsWith('/painel/login'), page.url())
  await page.screenshot({ path: `${out}/4-outro-tenant-pede-login.png` })

  // Logout
  await page.goto(TENANT + '/painel/')
  await page.getByRole('button', { name: 'Sair' }).click()
  await page.waitForURL('**/painel/login')
  check('logout volta para login', true)
  await ctx.close()
}

{ // 4. Atendente restrito à unidade Ponta Verde
  const { ctx, page } = await newPage()
  await login(page, `pizzaria-demo.${BASE_DOMAIN}`, 'atendente@pizzaria.localhost', attendantPw)
  await page.getByText('Unidade Ponta Verde').waitFor()
  const items = await page.locator('main li').allTextContents()
  check('atendente vê só a sua unidade', items.length === 1, JSON.stringify(items.map(i => i.split('\n')[0].trim())))
  await ctx.close()
}

{ // 5. Painel SerTec
  const { ctx, page } = await newPage()
  await page.goto(ADMIN + '/login')
  await page.getByLabel('E-mail').fill('admin@sertec.localhost')
  await page.getByLabel('Senha').fill(adminPw)
  await page.getByRole('button', { name: 'Entrar' }).click()
  await page.getByRole('heading', { name: 'Estabelecimentos' }).waitFor()
  await page.screenshot({ path: `${out}/5-admin.png` })
  check('superadmin entra no painel SerTec', true)
  await ctx.close()
}

await browser.close()
done()

// Todas as telas em celular (360 px) e tablet (768 px): nada pode "vazar"
// para o lado (rolagem horizontal da página). Tabelas rolam dentro da caixa.
import { launch, reporter, TENANT, ADMIN, PASSWORDS } from './lib.mjs'

const { check, done } = reporter()
const b = await launch()

async function verify(ctx, name, url) {
  const p = await ctx.newPage()
  await p.goto(url)
  await p.waitForLoadState('networkidle').catch(() => {})
  await p.waitForTimeout(400)
  const r = await p.evaluate(() => {
    const before = window.scrollX
    window.scrollTo(500, window.scrollY)
    const moved = window.scrollX
    window.scrollTo(before, window.scrollY)
    return { moved, scroll: document.documentElement.scrollWidth, w: document.documentElement.clientWidth }
  })
  check(`${name} sem rolagem lateral`, r.moved === 0, `${r.scroll}/${r.w}`)
  await p.close()
}

for (const width of [360, 768]) {
  const shop = await b.newContext({ viewport: { width, height: 740 } })
  for (const [n, u] of [['cardápio', '/'], ['acompanhar', '/acompanhar'], ['privacidade', '/privacidade']]) await verify(shop, `${width}px loja ${n}`, TENANT + u)
  const sp = await shop.newPage()
  await sp.goto(TENANT + '/')
  await sp.getByRole('button', { name: /Refrigerante lata/ }).click()
  await sp.getByRole('button', { name: /^Adicionar · R\$/ }).click()
  await sp.getByText('Refrigerante lata adicionado').waitFor()
  await sp.close()
  await verify(shop, `${width}px loja carrinho`, TENANT + '/carrinho')
  await verify(shop, `${width}px loja finalizar`, TENANT + '/finalizar')

  const m = await b.newContext({ viewport: { width, height: 740 } })
  const lp = await m.newPage()
  await lp.goto(TENANT + '/painel/login')
  await lp.getByLabel('E-mail').fill('dono@pizzaria.localhost')
  await lp.getByLabel('Senha').fill(PASSWORDS.owner)
  await lp.getByRole('button', { name: 'Entrar' }).click()
  await lp.waitForURL(TENANT + '/painel/')
  await lp.close()
  for (const path of ['', 'pdv', 'pedidos', 'historico', 'caixa', 'financeiro', 'relatorios', 'cardapio', 'opcoes', 'esgotados', 'promocoes', 'horarios', 'entrega', 'marca', 'pagamentos', 'equipe', 'privacidade', 'auditoria', 'assinatura', 'minha-conta']) {
    await verify(m, `${width}px painel /${path}`, `${TENANT}/painel/${path}`)
  }

  const a = await b.newContext({ viewport: { width, height: 740 } })
  const ap = await a.newPage()
  await ap.goto(ADMIN + '/login')
  await ap.getByLabel('E-mail').fill('admin@sertec.localhost')
  await ap.getByLabel('Senha').fill(PASSWORDS.admin)
  await ap.getByRole('button', { name: 'Entrar' }).click()
  await ap.getByRole('heading', { name: 'Estabelecimentos' }).waitFor()
  await ap.getByRole('link', { name: 'Pizzaria Demo' }).first().click()
  await ap.waitForURL(/\/estabelecimentos\//)
  const tenantPath = new URL(ap.url()).pathname.replace(/^\//, '')
  await ap.close()
  for (const path of ['', 'cobranca', 'auditoria', 'sistema', 'novo', tenantPath]) await verify(a, `${width}px SerTec /${path}`, `${ADMIN}/${path}`)
}

await b.close()
done()

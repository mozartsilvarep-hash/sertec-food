// Configuração comum dos testes ponta a ponta (ver e2e/README.md).
import { chromium } from 'playwright'
import { mkdirSync } from 'node:fs'

export const SHOTS = process.env.E2E_SHOTS ?? new URL('./shots', import.meta.url).pathname
mkdirSync(SHOTS, { recursive: true })

export const BASE_DOMAIN = process.env.E2E_BASE_DOMAIN ?? 'cardapios.localhost'
export const TENANT = `http://pizzaria-demo.${BASE_DOMAIN}`
export const OTHER_TENANT = `http://burger-demo.${BASE_DOMAIN}`
export const ADMIN = `http://admin.${BASE_DOMAIN}`
export const PASSWORDS = {
  owner: process.env.E2E_OWNER_PASSWORD ?? '',
  attendant: process.env.E2E_ATTENDANT_PASSWORD ?? '',
  admin: process.env.E2E_ADMIN_PASSWORD ?? '',
}

export function launch() {
  return chromium.launch({
    ...(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {}),
    args: [`--host-resolver-rules=MAP *.${BASE_DOMAIN} 127.0.0.1`],
  })
}

export function reporter() {
  const results = []
  return {
    check: (name, ok, extra = '') => results.push(`${ok ? 'OK    ' : 'FALHOU'} ${name} ${extra}`),
    done: () => {
      console.log(results.join('\n'))
      if (results.some((r) => r.startsWith('FALHOU'))) process.exitCode = 1
    },
  }
}

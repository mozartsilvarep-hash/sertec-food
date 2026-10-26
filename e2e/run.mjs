// Roda todos os cenários em sequência e falha se algum falhar.
import { spawnSync } from 'node:child_process'
import { readdirSync } from 'node:fs'

const only = process.argv[2]
const specs = readdirSync(new URL('.', import.meta.url)).filter((f) => /^s\d-.*\.mjs$/.test(f) && (!only || f.includes(only))).sort()
let failed = 0
for (const spec of specs) {
  console.log(`\n▶ ${spec}`)
  const r = spawnSync(process.execPath, [new URL(spec, import.meta.url).pathname], { stdio: 'inherit', env: process.env })
  if (r.status !== 0) failed++
}
console.log(failed ? `\n${failed} cenário(s) com falha` : '\nTodos os cenários passaram')
process.exit(failed ? 1 : 0)

// Roda todos os cenários em sequência e falha se algum falhar.
import { spawnSync } from 'node:child_process'
import { readdirSync } from 'node:fs'

const only = process.argv[2]
// Ordem numérica (s2 antes de s10). s99 troca senha: roda por último.
const num = (f) => Number(/^s(\d+)-/.exec(f)[1])
const specs = readdirSync(new URL('.', import.meta.url)).filter((f) => /^s\d+-.*\.mjs$/.test(f) && (!only || f.includes(only))).sort((a, b) => num(a) - num(b))
let failed = 0
for (const spec of specs) {
  console.log(`\n▶ ${spec}`)
  const r = spawnSync(process.execPath, [new URL(spec, import.meta.url).pathname], { stdio: 'inherit', env: process.env })
  if (r.status !== 0) failed++
}
console.log(failed ? `\n${failed} cenário(s) com falha` : '\nTodos os cenários passaram')
process.exit(failed ? 1 : 0)

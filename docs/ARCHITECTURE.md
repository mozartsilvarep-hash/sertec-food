# Arquitetura v0.1

## Stack
Laravel/PHP, Vue 3/TypeScript, MariaDB, Redis, Laravel Queue/Horizon, Reverb, Nginx, Docker. Monólito modular, três frontends: admin, merchant, storefront.

## Multi-tenancy
Banco compartilhado, `tenant_id` obrigatório em entidades de negócio. `branches` representam unidades de um tenant. TenantContext resolvido por domínio verificado, nunca confiando em tenant_id enviado pelo cliente. Jobs carregam tenant_id explicitamente. Policies, escopos, chaves estrangeiras compostas e testes de isolamento em todas as operações.

## Domínios
Subdomínio padrão `slug.cardapios.sertecsolution.com.br` (configurável). Domínio próprio por DNS + prova de posse + emissão de TLS. Integração com site existente inicialmente por link/subdomínio, não por iframe de checkout.

## Integrações
PaymentGateway interface, implementação MercadoPagoGateway. Credenciais de cada restaurante criptografadas. Webhook inbox com verificação de autenticidade, deduplicação, reconciliação por API e filas. NotificationGateway abstrato; MVP com status web e mensagens manuais, API WhatsApp futura.

## Pedidos
Estados de pagamento separados de estados operacionais. Fluxo operacional: pending_acceptance -> accepted -> preparing -> ready -> out_for_delivery -> completed; ramificações rejected/cancelled. Retirada pula out_for_delivery. Estado de pagamento: pending -> paid/failed/expired/refunded/partially_refunded.

## Segurança e operação
Autenticação Sanctum com cookies seguros; RBAC; CSRF, rate limits, validação e auditoria. Segredos fora do repositório. Backups testados, monitoramento, retenção LGPD, idempotency keys, transações, filas com retry e dead-letter/failed_jobs. Não armazenar dados de cartão.

---

## Decisões da Sprint 0 (registro)

Cada decisão abaixo foi aprovada antes da implementação. Alterá-las exige nova decisão registrada aqui.

### D1 — Monorepo e versões
`backend/` (Laravel 13, PHP 8.4 no Docker; código compatível com 8.3), `frontend/` (pnpm workspace: `apps/{storefront,merchant,admin}`, `packages/{ui,api-client}`; Vue 3.5, TypeScript 6.0, Vite 8, Tailwind CSS 4, Vitest), `docker/`, `.github/workflows/ci.yml`. TypeScript fixado em 6.0.x porque o typescript-eslint ainda não suporta o 7.x.

### D2 — Topologia de hosts (mesma origem)
| Host | Conteúdo |
|---|---|
| `{slug}.{TENANCY_BASE_DOMAIN}/` | cardápio público (storefront) |
| `{slug}.{TENANCY_BASE_DOMAIN}/painel/` | painel do restaurante |
| `{TENANCY_ADMIN_HOST}` | painel SerTec |
| qualquer host acima `/api/v1/*` | Laravel |

Domínios próprios verificados funcionam igual ao subdomínio. Como SPA e API estão na mesma origem: sem CORS (`config/cors.php` com `paths: []`), cookies *host-only* (`SESSION_DOMAIN` vazio), CSRF pelo `PreventRequestForgery` do Laravel 13 (Sec-Fetch-Site + token `XSRF-TOKEN`).

### D3 — Resolução do tenant (`ResolveTenantFromHost`)
Host normalizado → `tenant_domains.hostname` com `verified_at` não nulo → tenant. Host desconhecido ou não verificado: 404. Tenant `pending|suspended|cancelled`: 403 `tenant_unavailable`. Nunca se lê `tenant_id` de header, query ou body (há teste e teste de arquitetura para isso). Slugs reservados em `config/tenancy.php`. O subdomínio de plataforma é gravado em `tenant_domains` com `type=platform_subdomain`, verificado na criação, então há um único caminho de resolução.

### D4 — `TenantContext` fail-closed
Serviço *scoped* (novo por requisição e por job). `BelongsToTenant` adiciona o `TenantScope` (filtro `tenant_id`), preenche `tenant_id` no `creating`, bloqueia `tenant_id` divergente (`CrossTenantWrite`) e torna `tenant_id` imutável. **Sem contexto, qualquer consulta lança `MissingTenantContext`** — nunca devolve dados de todos os tenants. Modo plataforma (`runAsPlatform`) remove o filtro de leitura e exige `tenant_id` explícito na escrita; só pode ser usado nos arquivos listados em `tests/Arch/ScopeBypassTest.php`.

### D5 — Jobs
Jobs de negócio estendem `TenantJob`: capturam o `tenant_id` no dispatch e o middleware `TenantAware` restaura o contexto no worker. Job sem tenant falha. O worker do Laravel já descarta instâncias *scoped* entre jobs.

### D6 — Autenticação e sessões
Sanctum no modo SPA por sessão (guard `web`), sem a detecção de domínios *stateful* (não funcionaria com domínios próprios); o grupo de middleware `spa` inicia a sessão explicitamente. No login a sessão é regenerada e vinculada a um escopo (`merchant` + `tenant_id`, ou `platform`). Uma sessão apresentada em outro tenant ou outro escopo é **invalidada** e responde 401, mesmo que o usuário seja membro dos dois. Login de restaurante sem vínculo com o tenant do host recebe exatamente a mesma resposta de senha errada. Rate limit: 5/min por host+e-mail+IP e 30/min por IP.

### D7 — RBAC
`permissions` (catálogo global em `App\Modules\Identity\Permissions`), `roles` globais (`tenant_id` NULL: owner, manager, attendant, kitchen) ou do tenant, `tenant_users` com `branch_id` opcional. Permissões efetivas = união dos papéis dos vínculos ativos; `branch_id` NULL = todas as unidades. Recurso de outra unidade do mesmo tenant responde 404 (não revela existência). Sem permissão: 403 `forbidden`. A regra "papel global ou do mesmo tenant" é validada em `TenantUser` (FK composta não expressa "OU").

### D8 — Equipe SerTec
`users.platform_role` (`superadmin|support`, NULL para funcionários de restaurante). Rotas `/api/v1/admin/*` só existem no host admin (404 nos demais) e rodam em modo plataforma.

### D9 — Funcionalidades por plano
`plans.features_json` + `FeatureGate::enabled()` + middleware `feature:<chave>` (403 `feature_unavailable`). Catálogo em `App\Modules\Platform\Features\Feature`. Assinatura vigente = `trialing|active` e não expirada; plano inativo não libera nada. Preços dos planos estão em 0,00 de propósito (decisão comercial pendente).

### D10 — Erros e correlação
Todo erro em `/api/*` sai como `{code, message, details, request_id}` (`App\Http\Errors\ErrorRenderer`). 500 nunca expõe mensagem interna nem stack trace, mesmo com `APP_DEBUG`. `X-Request-Id` vem do Nginx (`$request_id`) ou é gerado (ULID), vai para logs, auditoria e resposta.

### D11 — Auditoria
`audit_logs` somente-inserção (model bloqueia update/delete). Login ok/falho, logout, criação de tenant e concessão de papel de plataforma são auditados. Falhas de login guardam `sha256(email)`, nunca o e-mail ou a senha.

### D12 — Serviços *scoped* nunca no construtor de controllers
O Laravel reaproveita a instância do controller entre requisições (rotas em cache, Octane). `TenantContext`, `TenantAccess` e `FeatureGate` entram por injeção no método. Há teste de arquitetura impedindo regressão. (Bug real encontrado pelos testes durante a Sprint 0.)

### D13 — Testes em MariaDB real
FKs compostas, colunas geradas e índices únicos com NULL se comportam diferente no SQLite. CI usa MariaDB 11.4; testes de isolamento validados também em 10.11. `tests/TestCase.php` descarta instâncias *scoped* e guards a cada requisição para reproduzir o PHP-FPM.

### D14 — Ajustes ao DATABASE.md
- `UNIQUE(tenant_id,user_id,branch_id)` não impede duplicata com `branch_id` NULL no MariaDB → coluna gerada `branch_scope = COALESCE(branch_id,0)` no UNIQUE.
- `roles`: UNIQUE(`COALESCE(tenant_id,0)`, `key`) via coluna gerada `tenant_scope`.
- `tenant_domains`: no máximo um primário por tenant via coluna gerada `primary_tenant_id = IF(is_primary, tenant_id, NULL)` UNIQUE; acrescentados `type` e `verification_token_hash`.
- `tenants`, `branches`, `users`: `public_id` ULID (rotas usam só o ULID; o id interno nunca sai na API).
- `audit_logs`: acrescentados `ip` e `request_id`.

### D15 — Reaproveitamento de código aberto
TastyIgniter e RestoPOS foram avaliados (ver `docs/REFERENCES.md`). Nenhum serve de base (um é single-tenant, o outro é banco-por-tenant em Laravel 8/Vue 2). São referência de desenho para as Sprints 1–3 e pós-MVP; nenhum código foi copiado na Sprint 0 (`THIRD_PARTY_NOTICES.md`).

### Pendências conhecidas (fora da Sprint 0)
- `composer.lock` e `pnpm-lock.yaml`: o `pnpm-lock.yaml` está no repositório; o `composer.lock` deve ser gerado no primeiro `make setup` (o ambiente de desenvolvimento desta sprint não tinha acesso ao Packagist) e commitado.
- Cache da resolução de host (Redis) quando houver volume; hoje é 1 consulta indexada por requisição.
- CSP, HSTS e Nginx de produção: Sprint 5.
- CRUD de tenants/unidades/funcionários pela API: sprints seguintes.

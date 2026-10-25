# SerTec Food

SaaS multi-tenant de cardápios digitais, pedidos e pagamentos da SerTec Solution.
Leia `AGENTS.md` e `docs/` antes de contribuir.

## Estado atual: MVP completo (Sprints 0–5) — pronto para o piloto

**Colocar em produção:** `docs/OPERATIONS.md` (servidor, DNS, HTTPS automático, cadastro de restaurantes, Mercado Pago, backups, LGPD, incidentes).

Sprint 5: painel SerTec (restaurantes, domínios próprios, auditoria), equipe e "Minha conta", stack de produção com Caddy + HTTPS sob demanda, meta tags por restaurante, saúde/logs, backups com teste de restauração, LGPD e testes ponta a ponta em `e2e/`.

Sprint 4: painel ao vivo com alerta sonoro, comanda para térmica, histórico, resumo, CSV e auditoria.

Sprint 3: Pix e cartão pelo Mercado Pago (conta de cada restaurante), webhook assinado, reconciliação e reembolso.

Sprint 2: carrinho e checkout sem login, entrega por bairro ou retirada, pagamento na entrega, pedido com histórico imutável, máquina de estados no painel e acompanhamento por link.

Sprint 1: cardápio público com busca e pizza meio a meio, painel de produtos/opções/esgotados/horários/marca, preço calculado no servidor, upload seguro de fotos.

### Sprint 0
Docker local, CI, lint, Laravel 13, três SPAs Vue 3 + Tailwind, MariaDB, Redis, migrations do núcleo SaaS, autenticação por sessão, `TenantContext` fail-closed, RBAC por tenant/unidade, funcionalidades por plano, auditoria, seeders e testes de isolamento. Decisões em `docs/ARCHITECTURE.md` (seção "Decisões da Sprint 0").

## Rodando localmente
Requisitos: Docker com Compose, `make`, `openssl`.

```bash
make setup        # cria .env com senhas aleatórias, sobe containers, instala, migra e semeia
```
O terminal mostra **uma única vez** as senhas de demonstração. Depois:

| Endereço | O que é |
|---|---|
| http://pizzaria-demo.cardapios.localhost | cardápio (shell da Sprint 0) |
| http://pizzaria-demo.cardapios.localhost/painel | painel do restaurante |
| http://burger-demo.cardapios.localhost/painel | outro tenant (teste o isolamento) |
| http://admin.cardapios.localhost | painel SerTec |

`*.localhost` aponta para 127.0.0.1 nos navegadores modernos; não precisa editar `/etc/hosts`.

Outros comandos: `make help`, `make test`, `make lint`, `make rollback-check`, `make fresh`.

Criar um estabelecimento real:
```bash
docker compose exec php php artisan tenant:create pizzaria-do-ze "Pizzaria do Zé" \
  --owner-email=ze@exemplo.com --owner-name="Zé" --plan=cardapio --activate
```

## Estrutura
```
backend/   Laravel 13 (monólito modular: app/Modules/{Tenancy,Identity,Platform,Catalog,Ordering,Payments})
frontend/  pnpm workspace: apps/{storefront,merchant,admin}, packages/{ui,api-client}
docker/    dev (PHP-FPM, Nginx) e produção (docker/prod, docker/caddy)
ops/       backup, restauração e teste de restauração
e2e/       cenários Playwright no navegador + Mercado Pago falso
docs/      produto, arquitetura, API, banco, segurança, operação, roadmap, referências
```

## Testes
- `backend/tests/Isolation`: tenant A nunca lê/escreve dados de B (modelo, banco, jobs, HTTP).
- `backend/tests/Arch`: toda tabela com `tenant_id` tem índice e model com `BelongsToTenant`; atalhos que furam o isolamento só em arquivos autorizados.
- `frontend/**/*.test.ts`: cliente HTTP e telas de login/cardápio.

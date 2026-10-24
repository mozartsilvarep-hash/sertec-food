# Roadmap de implementação

## Sprint 0 — Fundação ✅ concluída (23/10/2026)
Docker local, CI, lint, testes, Laravel, Vue, MariaDB, Redis, migrations iniciais, autenticação, TenantContext, seeders e testes de isolamento. Acrescido: Tailwind, funcionalidades por plano (`FeatureGate`), `docs/REFERENCES.md`.

## Sprint 1 — Catálogo ✅ concluída (24/10/2026)
CRUD categorias/produtos/variantes/opções, upload seguro, disponibilidade, storefront. Incluir pizza meio a meio e horários de funcionamento (referência: `docs/REFERENCES.md`).

## Sprint 2 — Pedidos
Carrinho, precificação no servidor, checkout sem login, retirada/entrega, snapshots e máquina de estados. Incluir `order_totals` e zonas de entrega por bairro/raio/polígono.

## Sprint 3 — Pagamento
Mercado Pago sandbox, credenciais por tenant, webhook inbox, idempotência, reconciliação, reembolso básico.

## Sprint 4 — Operação
Painel em tempo real, alertas, acompanhamento por token, histórico e auditoria.

## Sprint 5 — Piloto
Subdomínios, HTTPS, backups, observabilidade, LGPD, testes E2E, piloto com 2–3 restaurantes.

## Pós-MVP
Cobrança self-service, WhatsApp Cloud API, impressão térmica, cupons avançados, relatórios, PDV e IA.

## Definition of Done
Migration e rollback testados; testes unitários, integração e isolamento; revisão de segurança; documentação da API; logs/erros; revisão mobile; homologação.

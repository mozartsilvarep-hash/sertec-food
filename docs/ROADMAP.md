# Roadmap de implementação

## Sprint 0 — Fundação ✅ concluída (23/10/2026)
Docker local, CI, lint, testes, Laravel, Vue, MariaDB, Redis, migrations iniciais, autenticação, TenantContext, seeders e testes de isolamento. Acrescido: Tailwind, funcionalidades por plano (`FeatureGate`), `docs/REFERENCES.md`.

## Sprint 1 — Catálogo ✅ concluída (24/10/2026)
CRUD categorias/produtos/variantes/opções, upload seguro, disponibilidade, storefront. Incluir pizza meio a meio e horários de funcionamento (referência: `docs/REFERENCES.md`).

## Sprint 2 — Pedidos ✅ concluída (24/10/2026)
Carrinho, precificação no servidor, checkout sem login, retirada/entrega, snapshots e máquina de estados. Incluir `order_totals` e zonas de entrega por bairro/raio/polígono.

## Sprint 3 — Pagamento ✅ concluída (25/10/2026, aguardando homologação com credenciais de teste reais)
Mercado Pago sandbox, credenciais por tenant, webhook inbox, idempotência, reconciliação, reembolso básico. Entregue: Pix no site (QR + copia e cola), cartão pelo Checkout Pro, pedido só chega ao restaurante depois do pagamento confirmado, reembolso total/parcial e automático ao cancelar. **Pendente de decisão:** conexão por OAuth ("Conectar com Mercado Pago") em vez de colar o Access Token — exige registrar o app SerTec no Mercado Pago.

## Sprint 4 — Operação
Painel em tempo real, alertas, acompanhamento por token, histórico e auditoria.

## Sprint 5 — Piloto
Subdomínios, HTTPS, backups, observabilidade, LGPD, testes E2E, piloto com 2–3 restaurantes.

## Pós-MVP
Cobrança self-service, WhatsApp Cloud API, impressão térmica, cupons avançados, relatórios, PDV e IA.

## Definition of Done
Migration e rollback testados; testes unitários, integração e isolamento; revisão de segurança; documentação da API; logs/erros; revisão mobile; homologação.

# Roadmap de implementação

## Sprint 0 — Fundação ✅ concluída (23/10/2026)
Docker local, CI, lint, testes, Laravel, Vue, MariaDB, Redis, migrations iniciais, autenticação, TenantContext, seeders e testes de isolamento. Acrescido: Tailwind, funcionalidades por plano (`FeatureGate`), `docs/REFERENCES.md`.

## Sprint 1 — Catálogo ✅ concluída (24/10/2026)
CRUD categorias/produtos/variantes/opções, upload seguro, disponibilidade, storefront. Incluir pizza meio a meio e horários de funcionamento (referência: `docs/REFERENCES.md`).

## Sprint 2 — Pedidos ✅ concluída (24/10/2026)
Carrinho, precificação no servidor, checkout sem login, retirada/entrega, snapshots e máquina de estados. Incluir `order_totals` e zonas de entrega por bairro/raio/polígono.

## Sprint 3 — Pagamento ✅ concluída (25/10/2026, aguardando homologação com credenciais de teste reais)
Mercado Pago sandbox, credenciais por tenant, webhook inbox, idempotência, reconciliação, reembolso básico. Entregue: Pix no site (QR + copia e cola), cartão pelo Checkout Pro, pedido só chega ao restaurante depois do pagamento confirmado, reembolso total/parcial e automático ao cancelar. **Pendente de decisão:** conexão por OAuth ("Conectar com Mercado Pago") em vez de colar o Access Token — exige registrar o app SerTec no Mercado Pago.

## Sprint 4 — Operação ✅ concluída (25/10/2026)
Painel em tempo real, alertas, acompanhamento por token, histórico e auditoria. Entregue: pulso de pedidos (≤ 3 s, também com a aba em segundo plano), alerta sonoro/título/notificação e destaque de aceite atrasado, comanda para térmica 58/80 mm, histórico com busca e filtros, resumo do período, exportação CSV, auditoria automática do painel com tela para o proprietário e contato do restaurante (WhatsApp/telefone) no acompanhamento.

## Sprint 5 — Piloto ✅ concluída (25/10/2026, falta só o piloto em si)
Subdomínios, HTTPS, backups, observabilidade, LGPD, testes E2E, piloto com 2–3 restaurantes. Entregue: painel SerTec (cadastro/ativação/suspensão de restaurantes, senha do proprietário, plano, domínio próprio com verificação por DNS, auditoria da plataforma), equipe e "Minha conta" no painel do restaurante, edição de unidades, stack de produção (Caddy com HTTPS automático sob demanda, cabeçalhos de segurança/CSP, PHP-FPM, fila, agendador), meta tags/Open Graph por restaurante, saúde e logs JSON, backup diário criptografado com teste de restauração, LGPD (política, retenção automática, atendimento ao titular) e suíte E2E no repositório. **Para o piloto (decisões da SerTec):** servidor, domínio/DNS, revisão jurídica da política de privacidade e contratos (DPA), cópia externa dos backups, preços dos planos.

## Pós-MVP
Cobrança self-service, WhatsApp Cloud API, impressão térmica, cupons avançados, relatórios, PDV e IA.

## Definition of Done
Migration e rollback testados; testes unitários, integração e isolamento; revisão de segurança; documentação da API; logs/erros; revisão mobile; homologação.

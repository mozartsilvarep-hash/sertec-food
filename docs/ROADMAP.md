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

## Sprint 6 — Promoções e cupons ✅ concluída (26/10/2026)
Promoções por produto (percentual, com período, destaque e selo), cupons (percentual com teto, valor fixo, entrega grátis; pedido mínimo, período, limites total e por cliente, primeiro pedido), faixa de aviso no cardápio e feed público de promoções no "contrato v1" lido pelo site institucional do restaurante (ex.: Yris Cakes), com CORS por site autorizado.

## Implantação ✅ (26/10/2026)
No ar numa VPS da OVH com aaPanel: o Nginx do aaPanel cuida do HTTPS e o SerTec Food roda em Docker atrás dele (`docs/DEPLOY-AAPANEL.md`). Primeira loja: Yris Cakes, em `loja.yriscakes.com.br`.

## Sprint 7 — Clientes e WhatsApp ✅ concluída (26/10/2026)
Tudo em português, nova regra de senha, zonas de entrega com "Remover" visível e bairros de Aracaju. Cliente reconhecido pelo celular (endereço parcial em outro aparelho; preenchimento completo no mesmo aparelho), acompanhar pedido pelo celular e pedido enviado ao WhatsApp da loja com número, itens e total.

## Sprint 8 — Cardápio ✅ concluída (26/10/2026)
Produto por dia da semana e horário, combos com produtos do cardápio, "leve N, pague M" (ex.: compre 1, leve outro) e botão "Chamar no WhatsApp" no pedido do painel.

## Sprint 9 — E-mail e "Esqueci a senha" ✅ concluída (26/10/2026)
SMTP próprio (@sertecsolution.com.br), recuperação de senha nos dois painéis, tela "Sistema" com e-mail de teste.

## Sprint 10 — Caixa (mini PDV) ✅ concluída (26/10/2026)
Livro-caixa por unidade, abrir/fechar com conferência, venda de balcão com troco, entradas/saídas, pedidos concluídos e estornos entram sozinhos, relatório do caixa e CSV; tela "Relatórios" com mais vendidos, dias e horários de pico. Recurso `cashbook` (brinde em todos os planos, desligável por plano).

## Sprint 11 — Cartão fidelidade ✅ concluída (26/10/2026)
Selos por celular (pedido concluído acima do mínimo), prêmio fixo ou percentual com teto, resgate no checkout, devolução ao cancelar, ajuste manual no painel e histórico. Recurso `loyalty` (brinde em todos os planos).

## Sprint 12 — Marca e visual ✅ concluída (26/10/2026)
Logo, capa, cor (com tons prontos), razão social, CNPJ, Instagram e WhatsApp da loja; cardápio redesenhado (topo com capa e logo, destaques, categorias que acompanham a rolagem, rodapé com dados legais).

## Sprint 13 — Cobrança da SerTec ✅ concluída (27/10/2026)
Planos editáveis com preço por período e recursos; mensalidade por restaurante (valor próprio, cortesia, "pago até"); faturas automáticas; avisos por e-mail (vence em breve, em atraso, pago); Pix na conta Mercado Pago da SerTec com webhook e baixa automática; baixa manual; suspensão continua manual.

## Pós-MVP
WhatsApp Cloud API (aviso automático), impressão térmica (agente local), relatórios, PDV e IA.

## Definition of Done
Migration e rollback testados; testes unitários, integração e isolamento; revisão de segurança; documentação da API; logs/erros; revisão mobile; homologação.

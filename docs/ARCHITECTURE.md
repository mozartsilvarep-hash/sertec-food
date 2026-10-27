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

## Decisões da Sprint 1 — Catálogo

### D16 — Modelo do catálogo
`menus` (opcionalmente por unidade) → `categories` → `menu_products` (posição do produto na categoria; `menu_id` derivado da categoria para não haver duas fontes da verdade) → `products` (soft delete = arquivar) → `product_variants` (todo produto tem ≥ 1 variante; preço único = 1 variante). Tabelas-pivô (`menu_products`, `product_option_groups`) são gravadas por models próprios com `BelongsToTenant`, nunca por `attach()`, para o `tenant_id` passar pelo contexto. Todas as relações usam FK composta `(tenant_id, x_id)`.

### D17 — Grupos de opções e pizza meio a meio
`option_groups.type`: `addon` (soma `price_delta × qtd`; `free_quantity` = N unidades grátis, sempre as mais baratas) ou `fraction` (sabores). Sabores têm preço **por tamanho** em `option_variant_prices`; sem linha = sabor indisponível naquele tamanho. Preço da pizza = preço da variante + regra do grupo: `highest` (sabor mais caro, padrão) ou `average` (média, arredondamento meio-centavo para cima). Obrigatório = `min_select > 0` (a coluna `required` do DATABASE.md foi substituída por essa regra). Máximo de 4 sabores.

### D18 — Preço calculado só no servidor
`PriceCalculator` é a fonte única do preço de um produto configurado (centavos inteiros, `App\Support\Money`). Valida variante, grupos (mín./máx.), opções (ativas, do produto, sem duplicata, quantidade), sabor por tamanho e esgotado na unidade; opção de outro tenant = "opção inválida". Erros: 422 `invalid_selection` com `details.reason`. O frontend tem uma prévia (`previewPrice`) só para exibição, testada contra os mesmos cenários.

### D19 — IDs vindos do cliente
Referências (categoria, grupo, variante, unidade) são resolvidas por `PublicIds::resolve()`, que consulta **com** o `TenantScope`. A regra `exists` do validador não é usada para IDs de tenant porque consultaria a tabela sem escopo e confirmaria IDs de outros estabelecimentos.

### D20 — Upload de imagens
Tipo pelo conteúdo (finfo + getimagesize), 5 MB, 200–6000 px, decodificação e **reencode para WebP** (remove EXIF/GPS e payloads embutidos), máx. 1200 px, nome gerado (`catalog/{tenant_public_id}/{ulid}.webp`). Disco `public` local servido pelo Nginx em `/storage/` na mesma origem (sem PHP nessa rota). S3/CDN depende de aprovação (serviço pago).

### D21 — Horários
`branch_opening_hours` (vários períodos por dia; fechamento ≤ abertura = termina no dia seguinte; igual = 24 h) e `branch_schedule_exceptions` (a data especial substitui o dia, sem cortar o período iniciado na véspera). Cálculo em `OpeningSchedule`, sempre no fuso do tenant. Desenho inspirado no WorkingSchedule do TastyIgniter, reescrito (sem cópia de código).

### D22 — Esgotado por unidade e nova permissão
`branch_product_availability` (ausência = disponível). Permissão `catalog.availability` (atendente e cozinha) para marcar esgotado só nas unidades do próprio vínculo; produto esgotado aparece como "Esgotado hoje" e o `PriceCalculator` recusa. A migration `2026_10_24_000600` sincroniza permissões/papéis em ambientes existentes.

### D23 — Marca e fonte
`tenants.brand_color` só aceita cores com contraste ≥ 4,5:1 com branco (validado no servidor e pré-visualizado no painel); a escala `--brand-*` é derivada no navegador. Títulos em Bricolage Grotesque **auto-hospedada** (`@fontsource`, OFL): o cardápio não faz requisição a terceiros (LGPD). O painel do restaurante mantém a identidade SerTec; a cor do restaurante vale no cardápio público.

### D24 — Limites de requisição
Público (`/storefront`, `/menus`, `/products`): 240/min por IP. Upload: 20/min por usuário.

## Decisões da Sprint 2 — Pedidos

### D25 — Carrinho no servidor, anônimo
`carts` + `cart_items` guardam só **o que** foi escolhido (produto, variante, `selections_json`, quantidade, observação). O cliente recebe um token aleatório de 48 hex (o banco guarda o SHA-256); validade de 48 h renovada a cada alteração; carrinho é por unidade. O preço é recalculado pelo `PriceCalculator` a cada leitura; itens que deixaram de ser válidos aparecem com `valid=false` e o motivo (nunca somem em silêncio).

### D26 — Checkout transacional e idempotente
`POST /checkout` exige `Idempotency-Key` (16–100 caracteres). Dentro de uma transação: (1) reserva a chave em `idempotency_keys` (UNIQUE por tenant) — requisição concorrente com a mesma chave bloqueia nesse INSERT e, após o commit da primeira, recebe a mesma resposta; (2) trava o carrinho (`FOR UPDATE`); (3) revalida horário, esgotados e preços; (4) calcula entrega; (5) grava pedido, snapshots, `order_totals` e histórico; (6) marca o carrinho como convertido. Mesma chave com dados diferentes = 409 `idempotency_conflict`. Falha de negócio desfaz a reserva (o cliente corrige e reenvia com a mesma chave). O frontend reaproveita a chave enquanto os dados não mudam.

### D27 — Snapshots e composição do total
`order_items`/`order_item_options` guardam nome, variante, opções e preços do momento da compra; `product_id` é só referência (produtos são arquivados, nunca apagados). `order_totals` registra subtotal, entrega e total na ordem do cálculo (desenho inspirado no TastyIgniter). Numeração visível sequencial por unidade (`order_sequences` com trava de linha; UNIQUE `(tenant_id, branch_id, order_number)`).

### D28 — Máquina de estados
`OrderStatus::nextFor()` define as transições; `OrderTransitions` é a única porta de mudança (pedido travado, validação, histórico com ator, auditoria). Retirada pula `out_for_delivery`. Recusa/cancelamento exigem motivo padronizado (`CancellationReason`). Transição inválida = 409 `invalid_transition` com a lista permitida.

### D29 — Pagamento na entrega (Sprint 2)
Métodos `cash` (com troco ≥ total) e `card_on_delivery`. `payment_status` começa `pending`, vira `paid` ao concluir o pedido e `voided` se recusado/cancelado. Métodos online (Sprint 3) só mudam por webhook/reconciliação, nunca pelo retorno do navegador.

### D30 — Entrega por bairro
`delivery_zones` por unidade com lista de bairros (comparação sem acento/maiúsculas), taxa, pedido mínimo, grátis acima de e prazo; `branches.pickup_enabled/delivery_enabled/pickup_eta_minutes`. **Pendentes de decisão:** raio/polígono (exige geocodificação, serviço externo normalmente pago) e preenchimento por CEP (ex.: ViaCEP).

### D31 — Cliente e acompanhamento
`customers` por tenant identificados pelo celular normalizado (10–11 dígitos com DDD), dados mínimos. Acompanhamento público por token de 40 hex (hash no banco) mostra estado, itens e totais, mas **não** telefone completo, rua/número, complemento, e-mail ou sobrenome. Atualização por polling (20 s cliente, 15 s painel) até o tempo real da Sprint 4. Checkout limitado a 10/min por host+IP.

## Decisões da Sprint 3 — Pagamento

### D32 — Mercado Pago com credenciais coladas pelo restaurante
Cada restaurante recebe na **própria** conta do Mercado Pago (a SerTec não intermedia dinheiro; split fica fora do MVP). O proprietário cola o Access Token e, opcionalmente, a assinatura secreta do webhook; o token é validado em `GET /users/me` (conta MLB) e gravado em `integration_connections.credentials` com o cast `encrypted:array` (APP_KEY). A API devolve só os 4 últimos caracteres; auditoria registra conexão/desconexão sem segredos. Modo `test`/`live` derivado do prefixo do token. **Alternativa pendente de decisão:** OAuth do Mercado Pago (exige registrar o aplicativo SerTec e guardar refresh token).

### D33 — Sem SDK
Cliente HTTP próprio (`MercadoPagoGateway`, ~200 linhas) atrás da interface `PaymentGateway`: menos dependências, controle do que vai para log e testes com `Http::fake`. Endpoints e formato da assinatura conferidos com o SDK oficial `mercadopago/sdk-php` (licença MIT, sem cópia de código). Pix pela API de Pagamentos (`/v1/payments`, QR na resposta) e cartão pelo Checkout Pro (`/checkout/preferences`): os dois geram notificações do tópico `payment` e são conciliados pelo mesmo `GET /v1/payments/{id}`. Dados de cartão nunca passam pelo SerTec Food.

### D34 — Pedido online só existe para o restaurante depois de pago
Novo status `awaiting_payment` (saída só pelo sistema: `OrderTransitions::system()`). Confirmado → `pending_acceptance` com histórico sem ator ("Pagamento confirmado pelo Mercado Pago"). Prazo de 30 min (+2 min de tolerância) vencido → `cancelled` com motivo `payment_not_completed` e `payment_status=expired`. Pago depois do cancelamento → reembolso automático.

### D35 — Fonte da verdade e conferências
O status nunca vem do navegador (retorno do Checkout Pro vai para `/pedido/retorno`, sem token e com a query ignorada) nem do corpo do webhook: `PaymentSync` sempre lê a API com o token do restaurante e, com pagamento e pedido travados (`FOR UPDATE`), confere `external_reference` = `payments.public_id`, `collector_id` = conta conectada, moeda BRL e valor exato em centavos. Qualquer divergência → `mismatch` + auditoria, pedido não é liberado. Pagamento confirmado nunca volta a pendente; outra tentativa aprovada no mesmo checkout é auditada como `payment.duplicate_approval` (tratamento manual).

### D36 — Webhook: caixa de entrada, assinatura e isolamento
Rota no host do restaurante (o tenant vem do Host, como em todo o sistema), sem sessão/CSRF. Assinatura `x-signature` verificada como no SDK oficial; com segredo configurado a ausência/erro dá 401. Sem segredo o evento é aceito, mas é inofensivo: só dispara uma consulta autoritativa. `payment_events` deduplica por `(provider, external_event_id)` (ID da notificação, ou hash de tópico+recurso+ação+x-request-id) e guarda só campos mínimos. Pagamento de outra conta consultado com o token do host = 404 → ignorado. Processamento em fila (`ProcessPaymentEvent`, 5 tentativas); erro no processamento nunca derruba a resposta ao provedor.
**Correção de infraestrutura encontrada no E2E:** o Nginx sobrescrevia o cabeçalho `X-Request-Id` com o ID próprio, o que invalidaria toda assinatura do Mercado Pago. O ID do Nginx agora vai no parâmetro FastCGI `SERTEC_REQUEST_ID` (preferido pelo `AssignRequestId`) e o cabeçalho do cliente chega intacto. O PHP troca `data.id` por `data_id` em `$_GET`; o controller lê a query string crua.

### D37 — Idempotência com o provedor
A cobrança é criada **depois** do commit do checkout (não segura transação esperando rede) com `X-Idempotency-Key = payments.public_id`: falha de rede → o acompanhamento oferece "Tentar de novo" com a mesma chave, sem cobrar duas vezes. Replay do checkout (mesma `Idempotency-Key`) não cria nova cobrança. Reembolsos usam `refunds.public_id` como chave e travam o pagamento durante a chamada (dois reembolsos simultâneos não passam do valor pago); falha do provedor fica registrada (`refunds.status=failed`) e o pagamento não muda.

### D38 — Reconciliação agendada
`payments:reconcile` a cada minuto (`schedule:work`, novo serviço `scheduler` no Compose; `withoutOverlapping` + `onOneServer`): por tenant ativo e no contexto dele, consulta pagamentos abertos (Pix por ID; Checkout Pro pela `external_reference`, escolhendo a tentativa mais avançada), vence os expirados e reenfileira eventos com falha. É o que confirma pagamentos em desenvolvimento, onde o host não é HTTPS e o Mercado Pago não alcança o webhook.

### D39 — Permissões
`payments.manage` (credenciais) só para o proprietário; `payments.refund` para proprietário e gerente. Sincronizadas em ambientes existentes pela migration `2026_10_25_000300`.

### D40 — Acompanhamento durante o pagamento
Enquanto `awaiting_payment`, o site consulta o pedido a cada 5 s (a rota de leitura não chama o provedor); "Já paguei" chama o provedor com limite de 12/min. Pix com QR, copia e cola e contagem regressiva; cartão com botão para o Checkout Pro (sandbox com credencial de teste).

## Decisões da Sprint 4 — Operação

### D41 — "Tempo real" por pulso, sem WebSocket (por enquanto)
Contador por unidade no cache (`order-pulse:{tenant}:{branch}`, Redis), incrementado **depois do commit** por qualquer `Order::saved`. O painel consulta `GET /merchant/orders/pulse` a cada 3 s e só recarrega a lista quando a versão muda. Latência ≤ 3 s, sem novo serviço, sem portas extras e com o mesmo modelo de sessão/tenant do resto da API. A consulta roda num **Web Worker**, cujos timers não sofrem o "intensive throttling" do Chrome em abas em segundo plano (que reduziria um `setInterval` a 1/min). Caminho de evolução quando o volume justificar: Laravel Reverb (WebSocket, gratuito) com canais privados por tenant/unidade — o pulso continua como fallback.

### D42 — Alertas
Som de dois tons gerado com Web Audio (sem arquivo de áudio/licença), ligado por um clique (exigência dos navegadores) e lembrado no aparelho; repete a cada 30 s enquanto houver pedido sem aceite. Título da aba com a contagem, notificação do sistema quando a aba está oculta (com permissão) e faixa fixa que fica vermelha quando o pedido mais antigo espera ≥ 5 min.

### D43 — Comanda
Página de impressão fora do layout (`/painel/pedidos/{id}/imprimir`), 58 ou 80 mm (preferência local), `@page { margin: 0 }`, abre o diálogo de impressão automaticamente. Para imprimir sem diálogo: Chrome com `--kiosk-printing` e a térmica como impressora padrão. ESC/POS direto fica para o "SerTec Print Agent" (pós-MVP, ver REFERENCES).

### D44 — Histórico, resumo e CSV
Uma única implementação de filtros (`OrderFilters`) para lista, resumo e exportação: unidades do usuário, nunca `awaiting_payment`, datas no fuso do tenant, período ≤ 1 ano, LIKE escapado. Faturamento conta só pedidos concluídos; reembolsos à parte. O CSV é gerado em streaming (`chunk(500)`) com o contexto do tenant reaberto explicitamente (o corpo é enviado depois que os middlewares terminam), sem telefone/endereço (minimização LGPD) e com proteção contra injeção de fórmulas.

### D45 — Auditoria do painel
Middleware `AuditMutations` registra toda escrita 2xx do painel (rota, entidade e nomes, nunca o corpo); ações com registro próprio mais rico ficam de fora. Leitura em `GET /merchant/audit` com filtro **explícito** por `tenant_id` (a tabela é exceção ao `TenantScope`), coberto por teste de isolamento e de sabotagem. Nova permissão `audit.view` só para o proprietário (migration `2026_10_26_000100`).

### D46 — Acompanhamento do cliente
Atualiza a cada 10 s (5 s aguardando pagamento) e oferece WhatsApp (celular da unidade) e ligação.

## Decisões da Sprint 5 — Piloto

### D47 — Painel SerTec
API `/admin/*` só no host admin, em modo plataforma (leitura entre tenants). Suporte consulta; escrita exige `superadmin`. Criação reaproveita o `TenantProvisioner` (o mesmo do `tenant:create`). Senha do proprietário redefinida pela SerTec é global (a conta pode estar em vários tenants) e encerra todas as sessões do usuário (`SessionTerminator`, única exceção autorizada ao Query Builder cru — tabela `sessions` sem tenant_id).

### D48 — Domínio próprio e HTTPS sob demanda
Domínio do cliente é cadastrado sem verificação e só passa a resolver o tenant depois que o TXT `_sertec-verify.{host}` = `sertec-verify={token}` é encontrado (token mostrado uma vez; banco guarda o hash). O Caddy emite certificados sob demanda (`on_demand_tls`) e pergunta antes a `/api/v1/internal/tls-ask`, que só autoriza o host admin e domínios verificados — subdomínios de plataforma são verificados na criação. Assim não é preciso certificado curinga (desafio DNS, dependente de provedor) e nomes arbitrários apontados para o servidor não consomem o limite do Let's Encrypt. Rotas `/api/v1/internal/*` são bloqueadas na borda.

### D49 — Borda de produção: Caddy + PHP-FPM
Caddy serve as SPAs compiladas e fala FastCGI direto com o PHP-FPM (sem Nginx em produção). `REMOTE_ADDR` é o IP real; por isso **nenhum proxy é confiável por padrão** (`TRUSTED_PROXIES` vazio) — confiar em `*` deixava o cliente forjar `X-Forwarded-For` e driblar limites por IP (coberto por teste). Cabeçalhos: HSTS, CSP restrita (`default-src 'self'`, imagens `data:` para o QR do Pix, `frame-ancestors 'none'`), nosniff, Referrer-Policy, Permissions-Policy. Imagens multi-stage (`docker/prod/Dockerfile`): build dos frontends, Composer sem dev com autoload autoritativo, PHP com opcache sem revalidação, caches do Laravel no start.

### D50 — Meta tags por restaurante
O Caddy entrega qualquer navegação do cardápio ao `StorefrontShellController`, que injeta (escapados) título, descrição, canonical, `theme-color` e Open Graph (nome, descrição, primeira foto do cardápio) no `index.html` do build. Link compartilhado no WhatsApp mostra o restaurante. Cache de 5 min por tenant, invalidado ao salvar a marca. Host desconhecido/suspenso recebe `noindex`.

### D51 — Equipe
O proprietário gerencia gerente/atendente/cozinha (um vínculo por pessoa, opcionalmente restrito a uma unidade). Proprietários são definidos pela SerTec. A conta é global: o restaurante só cria conta nova (senha temporária mostrada uma vez) ou redefine senha de conta **exclusiva** dele (`AccountSharing`, checagem em modo plataforma); quem já tem conta em outro estabelecimento entra com a senha que já tem e o nome da conta não é sobrescrito.

### D52 — Saúde e logs
`HealthCheck` verifica banco, cache/Redis, tamanho da fila e batimento do agendador (gravado a cada minuto). Exposto em `ops:status`, no healthcheck do container e em `/api/v1/internal/health`. Em produção os logs vão para stderr em JSON (Monolog `JsonFormatter`) com `request_id`. Sentry/APM ficam opcionais (serviço externo, decisão da SerTec).

### D53 — Backups
`ops/backup.sh`: `mariadb-dump --single-transaction` + tar das fotos, gzip, criptografia opcional AES-256 (openssl, PBKDF2 200k), retenção por dias; `ops/backup-test.sh` restaura o último backup num banco temporário, compara contagens com produção e apaga (validado localmente, inclusive falhando com a senha errada). Cópia para fora do servidor é obrigatória na operação (docs/OPERATIONS.md) e depende de destino escolhido pela SerTec.

### D54 — LGPD
Restaurante = controlador; SerTec = operadora. Política de privacidade modelo em `/privacidade` (revisão jurídica pendente). Retenção: `privacy:anonymize` diário remove nome, telefone, e-mail, endereço e observações de pedidos **finalizados** há mais de `PRIVACY_RETENTION_MONTHS` (24) e apaga clientes sem pedidos recentes; valores e itens permanecem. Titular: o restaurante consulta e apaga por celular (recusado com pedido em andamento); auditoria guarda só o hash do telefone. CSV sem telefone/endereço.

### D55 — E2E no repositório
`e2e/` reúne os cenários Playwright usados em cada sprint (57 verificações) e um Mercado Pago falso. Rodam contra o ambiente local depois de `demo:prepare-e2e` (só `APP_ENV=local`: senhas conhecidas, lojas sempre abertas, MP desconectado). Ainda não rodam no CI (exigiriam a stack completa); o CI valida o compose e o Caddyfile de produção e constrói as imagens.

### Nota de testes
O `TestCase` reaproveita o `Store` de sessão entre requisições do mesmo teste; testes que alternam sessões de usuários diferentes devem fazer as asserções de uma sessão antes de logar com outra (comportamento só do harness, não da aplicação).

## Decisões da Sprint 6 — Promoções e cupons

### D56 — Promoção percentual sobre a unidade inteira
Uma promoção por produto (sobreposição recusada) com desconto em centésimos de por cento (`percent_bp`) aplicado ao preço unitário completo (variante + sabores + adicionais) no `PriceCalculator`, com arredondamento inteiro ao centavo (`intdiv(c·(10000−bp)+5000, 10000)`). O navegador (`previewPrice`) usa a mesma fórmula só para exibir. O painel aceita "preço por" e converte em percentual sobre o "a partir de". Assim promoções funcionam também para pizzas meio a meio, em que o preço vem dos sabores. Os itens do pedido guardam o preço já com desconto.

### D57 — Cupons
Tipos: percentual (com teto opcional), valor fixo (limitado ao subtotal) e entrega grátis (zera só a taxa). Regras: pedido mínimo, período, limite total, limite por cliente (celular) e só no primeiro pedido. O checkout lê o cupom com `FOR UPDATE` dentro da transação, então pedidos simultâneos não passam do limite. O resgate fica em `coupon_redemptions` (UNIQUE por pedido) e o desconto entra em `order_totals` (`discount`) e `orders.discount_total`. Recusa ou cancelamento (inclusive o automático do Pix vencido) anula o resgate e devolve o uso. O recurso é liberado por plano (`coupons`); um tenant cujo plano perdeu o recurso tem os cupons recusados no checkout.

### D58 — Feed de promoções para o site do restaurante
`GET /api/v1/vitrine/promocoes.json` implementa o **contrato v1** já usado pelo site institucional da Yris Cakes (documento do projeto "Feed de promoções e avisos"), para que o site mostre promoções e a faixa de aviso direto da loja.
- É público e não tem dados de clientes nem cookies.
- O CORS é por estabelecimento: só as origens cadastradas recebem `Access-Control-Allow-Origin`, com correspondência exata e `Vary: Origin`.
- URLs e imagens usam o domínio principal verificado em HTTPS. Com isso, o domínio próprio do restaurante (ex.: `loja.yriscakes.com.br`) atende à regra do site de só aceitar links do próprio domínio.

### D59 — Faixa de aviso
Texto, link HTTPS opcional e período, guardados no tenant. Aparece no topo do cardápio e no feed (`faixa`). Fora do período, some dos dois.


## Decisões de implantação

### D60 — Instalação atrás do Nginx do aaPanel
Servidores com aaPanel já usam as portas 80/443 para outros sites. Nesse caso o SerTec Food não disputa as portas:
- O container `web` publica só em `127.0.0.1:8088` (`WEB_HTTP_BIND`/`WEB_HTTPS_BIND`) e usa `docker/caddy/Caddyfile.proxy` (`CADDY_CONFIG`). O Nginx do aaPanel faz o HTTPS e repassa.
- **IP real:** o Caddy só aceita `X-Forwarded-For` de redes privadas (o próprio proxy local) e em modo estrito (lê da direita para a esquerda e para no primeiro IP público). Um `X-Forwarded-For` forjado pelo visitante não muda o IP, mesmo que o Nginx use `$proxy_add_x_forwarded_for`. O PHP recebe o IP em `REMOTE_ADDR`, e o Laravel continua sem confiar em proxy (`TRUSTED_PROXIES` vazio). Assim os limites por IP e a auditoria continuam corretos.
- **Esquema:** o PHP recebe `HTTPS=on`, então URLs geradas (canonical, links do feed, URLs do Mercado Pago) saem em `https://`.
- **Certificados:** ficam com o aaPanel, um site por host. Não há emissão sob demanda; por isso `/internal/tls-ask` não é usado nesse modo e continua bloqueado de fora.
- **CI:** sobe as imagens de produção atrás de um Nginx configurado como o do aaPanel e confere IP real, HTTPS, CSP e o bloqueio da rota interna.

O modo padrão (Caddy dono das portas, certificados automáticos) continua igual. Guia: `docs/DEPLOY-AAPANEL.md`.

## Decisões da Sprint 7 — Clientes pelo celular e WhatsApp

### D61 — Idioma e regra de senha
O padrão passou a ser `pt_BR`, com as traduções do framework em `backend/lang/pt_BR`. Antes, as mensagens de validação saíam em inglês. A senha escolhida pela SerTec tem no mínimo 6 caracteres, com 1 maiúscula e 1 caractere especial (`PasswordPolicy`). Contra tentativa de adivinhar, vale o limite de login, que continua igual.

### D62 — Cliente reconhecido pelo celular, com endereço parcial
Qualquer pessoa pode digitar o celular de outra. Por isso `customers/lookup` devolve só:
- primeiro nome;
- rua e bairro do último endereço, com o número escondido.

Quando o cliente escolhe "usar este endereço", o navegador manda `use_saved_address`, e o endereço completo vai do banco direto para o pedido, sem passar pelo navegador. Quem fizer um pedido assim com o celular alheio não descobre o endereço:
- o acompanhamento público mostra só o bairro;
- a mensagem do WhatsApp também não leva rua nem número.

Pedidos anonimizados (LGPD) nunca entram. No mesmo aparelho, os dados completos ficam no `localStorage`, com o botão "Esquecer meus dados". A política de privacidade descreve os dois mecanismos.

### D63 — Acompanhar pelo celular
`orders/lookup` lista só número, horário, situação, total e unidade dos pedidos das últimas 24 h. Os detalhes continuam exigindo o link com token, guardado no aparelho que fez o pedido. As duas consultas por celular têm um limitador próprio, apertado por IP e por número, para dificultar a varredura de números.

### D64 — Pedido pelo WhatsApp sem integração paga
O WhatsApp entra por link `wa.me`, sem API paga. Logo depois do pedido, um botão abre o WhatsApp da loja com a mensagem pronta:
- número do pedido;
- itens;
- total e pagamento;
- bairro.

Depois, o botão manda só o número do pedido. O telefone usado é o da unidade ("Telefone / WhatsApp"), fixo ou celular. Uma integração oficial (WhatsApp Business API), com aviso automático, é paga e fica para quando a SerTec decidir.

### Zonas de entrega
O botão "Remover" fica no topo de cada zona, e uma zona totalmente vazia é descartada ao salvar. O painel também sugere os bairros de Aracaju, que entram com um toque.

## Decisões da Sprint 8 — Cardápio

### D65 — Disponibilidade por dia da semana e horário
`products.available_days` (0 = domingo … 6 = sábado; nulo = todos), `available_from` e `available_until` (opcionais).
- **Regras:** valem no fuso do restaurante. Uma faixa que passa da meia-noite conta para o dia em que começou. A checagem é feita no `PriceCalculator`, e por isso vale no carrinho e no checkout. Um item que já estava no carrinho fica inválido se o horário acabar.
- **No cardápio:** o produto continua visível, bloqueado, com o rótulo "Só sáb. e dom." ou "Das 11h às 15h".

### D66 — Combos reaproveitam os grupos de opções
Um combo é um produto `kind=combo` com preço fixo. Cada parte ("Escolha 2 bolos de pote") vira um grupo de opções "dono" do combo (`option_groups.owner_product_id`):
- tipo adicional, com mínimo = máximo = quantidade;
- uma opção por produto do cardápio (`options.product_id`), sem preço próprio.

Assim, o preço, a validação, o carrinho e os registros do pedido usam o motor existente sem caminho novo. Regras adicionais:
- **Disponibilidade:** cada item escolhido segue o produto (ativo, estoque na unidade, dia e horário).
- **Edição:** os grupos do combo não aparecem na lista de opções e só mudam pela tela do combo.
- **Proibido:** combo dentro de combo e combo contendo a si mesmo.
- **Economia exibida:** `compare_at` soma o item avulso mais barato de cada parte.

### D67 — "Leve N, pague M"
É uma promoção `type=bundle` com `buy_quantity` e `pay_quantity`, calculada no carrinho sobre a quantidade total do produto. As unidades grátis saem das mais baratas.
- Entra em `order_totals` como `promotion` e soma em `orders.discount_total`.
- O pedido mínimo e o cupom valem sobre o subtotal já descontado.
- Continua valendo uma promoção vigente por produto, de qualquer tipo.
- No feed do site, vai com o selo e sem `precoDe`.

### D68 — WhatsApp do cliente no painel
O detalhe do pedido tem o botão "Chamar no WhatsApp", um link `wa.me` para o celular do cliente com a mensagem "Olá, Ana! Aqui é da Loja, sobre o seu pedido nº 12." Não há integração paga, como em D64.

## Decisões da Sprint 9 — E-mail e "Esqueci a senha"

### D69 — Recuperação de senha nos dois painéis
Usa o broker de senha do Laravel: o token fica como hash em `password_reset_tokens`, vale 60 minutos e permite um e-mail por minuto.
- **Resposta sempre igual:** o pedido devolve a mesma mensagem para qualquer e-mail, existente ou não.
- **Quem recebe:** só quem pode entrar naquele painel. No restaurante, membro ativo do restaurante do host; no painel SerTec, a equipe da plataforma. Por isso o link de um painel não funciona no outro.
- **Onde aponta o link:** para o host em que o pedido foi feito. Não há risco de host forjado, porque hosts desconhecidos não chegam ao app.
- **Envio:** o job `SendPasswordResetLink` vai para a fila só com o id do usuário e cria o token dentro do próprio job. Assim, o link nunca fica em fila, `failed_jobs` ou log, e o tempo de resposta não revela se o e-mail existe.
- **Ao redefinir:** a nova senha segue a `PasswordPolicy`, e todas as sessões da pessoa são encerradas.
- **Limites:** 5 pedidos por minuto por IP e 10 por hora por e-mail.

### D70 — SMTP próprio e teste pelo painel
O envio usa o mailer `smtp` com os dados do `.env.production`; o código não guarda nenhuma credencial. O painel SerTec → Sistema mostra a configuração, sem a senha. O botão de teste envia só para o próprio superadmin, sem destinatário livre, para não virar ferramenta de spam. Os e-mails têm modelo HTML e uma versão em texto puro, em português.

## Decisões da Sprint 10 — Caixa (mini PDV) e relatórios

### D71 — Livro-caixa por unidade
Módulo `Cash`, com duas tabelas:
- `cash_sessions`: abertura com troco inicial e fechamento com o dinheiro contado. Ficam registrados o esperado e a diferença, e só pode haver um caixa aberto por unidade (trava na unidade).
- `cash_entries`: cada lançamento tem tipo (entrada/saída), categoria fixa, forma de pagamento, valor positivo, descrição e itens.

Regras:
- **Lançamentos não são apagados**, só estornados com motivo, e não podem ser estornados depois que o caixa foi fechado.
- **Só a forma "dinheiro"** mexe no valor esperado da gaveta. Reforço e sangria são sempre em dinheiro.
- **Categorias fixas** para o relatório: venda no balcão, pedido do cardápio, reforço, outra entrada; sangria, fornecedores, funcionários, contas, estorno, outra saída.

### D72 — Entradas automáticas
- Um pedido concluído vira entrada "Pedido do cardápio" (`source = order:ID`, único). O pagamento na entrega entra como dinheiro ou cartão; o pago pelo site, como "Pago pelo site".
- Um reembolso de pedido que já estava no caixa vira saída "Estorno de pedido" (`refund:ID`).
- Essas entradas entram no caixa aberto da unidade, se houver.

### D73 — Venda de balcão
- Itens do cardápio usam o preço do tamanho, calculado pelo servidor (sem adicionais nem promoções; é uma venda simples de balcão).
- Itens avulsos usam o valor digitado pelo próprio operador.
- Aceita desconto. O troco é calculado no servidor, e um valor recebido menor que o total é recusado.
- Só funciona com o caixa aberto.

### D74 — Permissões e plano
- `cash.operate` (proprietário, gerente e atendente): abrir, fechar, vender e lançar. Atendente presa a uma unidade só opera aquela unidade e só vê o caixa aberto.
- `cash.manage` (proprietário e gerente): estornar, ver o relatório do período e o histórico de fechamentos, e exportar CSV (com BOM e fórmulas neutralizadas).
- O recurso `cashbook` está liberado em todos os planos como brinde. A migration liga o recurso nos planos existentes sem regravar o resto, e a SerTec pode desligar por plano depois.

### Relatórios de pedidos
`reports/summary` ganhou:
- mais vendidos (top 10, só concluídos);
- faturamento por dia;
- pedidos por hora (pico);
- entrega × retirada.

A nova tela "Relatórios" tem atalhos de período.

## Decisões da Sprint 11 — Cartão fidelidade

### D75 — Selos por celular, sem cadastro
O cartão é o celular do cliente dentro de cada restaurante (`loyalty_cards`, único por tenant e telefone). A regra fica em `loyalty_programs`, uma por restaurante:
- quantos selos valem um prêmio;
- pedido mínimo para ganhar selo;
- tipo de prêmio: valor fixo ou percentual com teto.

Como o cartão funciona:
- **Selo:** é dado quando o pedido é **concluído** (`OrderTransitions`) e o valor dos itens, sem a entrega, atinge o mínimo. Cada pedido dá no máximo um selo (único no histórico).
- **Prêmio:** a cada N selos completos, vira 1 prêmio.
- **Histórico:** `loyalty_ledger` registra selo, prêmio usado, prêmio devolvido e ajuste manual.

### D76 — Prêmio no checkout
O prêmio é pedido com `use_loyalty`. O cartão é travado (FOR UPDATE) e o desconto é aplicado sobre os itens, depois de "leve N, pague M" e do cupom. Ele entra em `order_totals` como `loyalty` e soma em `discount_total`.
- **Sem prêmio:** 422 `loyalty_no_reward`.
- **Pedido recusado ou cancelado:** o prêmio volta (`restoreFor`, junto com a anulação do cupom).

### D77 — Privacidade e plano
O que o público vê é só a contagem de selos e prêmios (`customers/lookup`, acompanhamento), sem dados pessoais.
- **Pedido de exclusão (LGPD):** apaga o cartão e o histórico.
- **Retenção:** cartões sem movimento no prazo de retenção são apagados.
- **Relatório do titular:** inclui o cartão.
- **Ajuste manual pelo painel** (ex.: compra no balcão): fica auditado, com o telefone guardado só como hash.
- **Plano:** o recurso `loyalty` está liberado em todos os planos como brinde, desligável por plano.

### Nota técnica
Controllers não recebem pelo construtor serviços que dependem do tenant, porque a instância é reaproveitada entre requisições. Isso já vinha documentado em `MerchantAuthController`. `CustomerLookupController` passou a receber `CustomerDirectory` por parâmetro do método.

## Decisões da Sprint 12 — Marca e visual

### D78 — Logo e capa com o mesmo upload seguro das fotos
A validação e o reencode em WebP saíram de `ProductImageStore` para `App\Support\Images\WebpEncoder`. O encoder confere o tipo pelo conteúdo, limita tamanho e dimensões e descarta EXIF e payloads. `BrandImageStore` usa o mesmo encoder:
- **Logo:** recorte central quadrado de 512 px.
- **Capa:** recorte 2,5:1 de até 1600 px.

As imagens ficam em `brand/{tenant}/` no disco público. Ao trocar ou remover, o arquivo antigo é apagado.

A capa vira a prévia do link (og:image), com a foto de produto como alternativa, e a logo vira o ícone da aba.

### D79 — Identificação da empresa
`legal_name`, `cnpj`, `instagram` e `whatsapp` ficam em `tenants` e são editados pelo dono em Marca. O rodapé do cardápio mostra razão social, CNPJ, endereço e telefone, como pede o Decreto 7.962/2013.
- **CNPJ:** validado com dígitos verificadores, já no formato alfanumérico (IN RFB 2.229/2024). É guardado só com os 14 caracteres.
- **WhatsApp:** guardado com 55. Sem número próprio, o botão do cardápio usa o telefone da unidade.

### D80 — Cardápio redesenhado
- **Topo:** capa (ou faixa na cor da marca), logo sobreposta (ou monograma com as iniciais), nome, situação e botões de WhatsApp, Instagram e "Como chegar" (Google Maps).
- **Destaques:** faixa horizontal com fotos dos produtos em promoção, combos e preços "de/por". Só aparece com 2 ou mais produtos.
- **Categorias:** a barra destaca a categoria visível.
- **Rodapé:** dados legais.

Continua sem CSS por cliente: tudo vem de `--brand-*`. O painel oferece 9 tons prontos com contraste AA.

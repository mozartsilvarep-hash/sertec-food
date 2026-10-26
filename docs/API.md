# Contrato inicial de API v0.1

Base `/api/v1`. Respostas JSON; erros padronizados `{code,message,details,request_id}`. Paginação cursor para pedidos e catálogo extenso. Não aceitar tenant_id do browser como autoridade.

## Público
GET /storefront; GET /menus; GET /products/{id}; POST /carts; POST /carts/{token}/items; PATCH /carts/{token}/items/{id}; POST /checkout; GET /orders/track/{token}.

## Restaurante (autenticado)
GET /merchant/orders; GET /merchant/orders/{id}; PATCH /merchant/orders/{id}/status; CRUD /merchant/products; CRUD /merchant/categories; CRUD /merchant/option-groups; GET /merchant/settings; PATCH /merchant/settings.

## Superadmin
GET /admin/tenants; POST /admin/tenants; PATCH /admin/tenants/{id}; GET /admin/audit-logs.

## Integrações
POST /webhooks/mercadopago (verificar assinatura e origem segundo documentação vigente; responder rápido e processar em fila).

## Contratos críticos
POST /checkout exige `Idempotency-Key`; retorna order_public_id, payment_status e dados seguros para completar pagamento. Status operacional validado por máquina de estados. Nunca expor credenciais, payloads integrais de webhook ou tokens em logs públicos.

---

## Implementado na Sprint 0

Todos os erros: `{code, message, details, request_id}`. IDs expostos são sempre ULIDs públicos.

| Método e rota | Host | Autenticação | Respostas relevantes |
|---|---|---|---|
| `GET /api/v1/storefront` | tenant | pública | 200 `{data:{name,slug,timezone}}` · 404 host desconhecido · 403 `tenant_unavailable` |
| `POST /api/v1/merchant/auth/login` | tenant | — | 200 perfil · 422 `invalid_credentials` (inclusive sem vínculo com o tenant) · 422 `validation_failed` · 429 `too_many_requests` |
| `POST /api/v1/merchant/auth/logout` | tenant | sessão merchant | 204 |
| `GET /api/v1/merchant/me` | tenant | sessão merchant | 200 `{data:{user,tenant,roles,permissions,all_branches}}` · 401 sessão de outro tenant/escopo |
| `GET /api/v1/merchant/branches` | tenant | `branches.view` | 200 lista (filtrada pelas unidades do funcionário) · 403 `forbidden` |
| `GET /api/v1/merchant/branches/{ulid}` | tenant | `branches.view` | 200 · 404 se de outro tenant ou fora do escopo do funcionário |
| `POST /api/v1/admin/auth/login` | admin | — | 200 · 422 `invalid_credentials` · 404 em host de tenant |
| `POST /api/v1/admin/auth/logout` | admin | sessão platform | 204 |
| `GET /api/v1/admin/me` | admin | sessão platform | 200 · 401 · 403 se perdeu `platform_role` |
| `GET /up` | qualquer | — | health check |

Códigos de erro estáveis: `not_found`, `unauthenticated`, `forbidden`, `tenant_unavailable`, `feature_unavailable`, `invalid_credentials`, `validation_failed` (`details.fields`), `too_many_requests`, `csrf_token_mismatch`, `internal_error`.

Comandos de console: `php artisan tenant:create {slug} {name} --owner-email= --owner-name= [--plan=] [--activate] [--timezone=]` e `php artisan platform:create-admin {email} {name} [--role=superadmin|support]` (senhas aleatórias exibidas uma vez).

## Implementado na Sprint 1 — Catálogo

Valores monetários são strings `"12.50"`. IDs são ULIDs públicos. Atualização de produto e grupo é `PUT` com o recurso completo.

### Público (host do tenant, sem login)
| Rota | Resposta |
|---|---|
| `GET /api/v1/storefront` | `name, slug, timezone, description, brand_color, branches[{id, name, phone, address, has_hours, open, closes_at, next_opening_at}]` |
| `GET /api/v1/menus?branch={ulid}` | `branch_id, menus[{id, name, categories[{id, name, description, products[{id, name, description, image_url, available, has_options, price_from}]}]}]` — só itens ativos; unidade padrão = primeira ativa |
| `GET /api/v1/products/{ulid}?branch=` | resumo + `variants[]` + `option_groups[{id, name, type, min_select, max_select, required, free_quantity, pricing_rule, options[{id, name, description, price_delta, max_quantity, prices}]}]` (`prices`: ULID da variante → preço, só em sabores) |

### Painel do restaurante
| Rota | Permissão |
|---|---|
| `GET /merchant/menus` · `POST` · `PATCH /{id}` · `DELETE /{id}` | `catalog.view` / `catalog.manage` |
| `GET /merchant/categories?menu=` · `POST` · `PATCH /{id}` · `DELETE /{id}` | `catalog.view` / `catalog.manage` |
| `GET /merchant/products?search=&per_page=&cursor=` (cursor) · `GET /{id}` | `catalog.view` |
| `POST /merchant/products` · `PUT /{id}` · `DELETE /{id}` (arquiva) | `catalog.manage` |
| `POST /merchant/products/{id}/image` (multipart `image`) · `DELETE /{id}/image` | `catalog.manage` |
| `GET /merchant/option-groups` · `GET /{id}` · `POST` · `PUT /{id}` · `DELETE /{id}` | `catalog.view` / `catalog.manage` |
| `GET /merchant/branches/{id}/availability` | `catalog.view` |
| `PUT /merchant/branches/{id}/availability/{product}` `{available}` | `catalog.availability` + acesso à unidade |
| `GET /merchant/branches/{id}/hours` | `branches.view` + acesso à unidade |
| `PUT /merchant/branches/{id}/hours` `{weekly[{weekday,opens_at,closes_at}], exceptions[{date,closed,opens_at,closes_at,note}]}` | `branches.manage` + acesso à unidade |
| `GET /merchant/settings` · `PATCH` `{description, brand_color}` | `settings.view` / `settings.update` |

Produto (`POST`/`PUT`): `{name, description, active, variants[{id?, name, price, active}], option_group_ids[], category_ids[]}`.
Grupo (`POST`/`PUT`): `{name, type: addon|fraction, min_select, max_select, free_quantity, pricing_rule: highest|average (fraction), active, options[{id?, name, description, price_delta, max_quantity, active, variant_prices[{variant, price}]}]}`.

Erros novos: `invalid_selection` (422, `details.reason`: `variant_invalid`, `option_invalid`, `option_duplicated`, `option_quantity_invalid`, `group_min_not_met`, `group_max_exceeded`, `flavor_unavailable_for_size`, `product_sold_out`, `product_unavailable`) — usado pelo carrinho na Sprint 2.

## Implementado na Sprint 2 — Pedidos

### Público (host do tenant, sem login)
| Rota | Descrição |
|---|---|
| `POST /api/v1/carts` `{branch_id?}` | 201 `{token, branch_id, items[], subtotal, valid, count, expires_at}` — o `token` só é devolvido aqui |
| `GET /api/v1/carts/{token}` | carrinho com preços recalculados; itens inválidos com `valid:false` e `problem{reason,message}` |
| `POST /api/v1/carts/{token}/items` `{product_id, variant_id, options[{option,quantity}], quantity, notes?}` | 201; 422 `invalid_selection` se a configuração não for válida |
| `PATCH /api/v1/carts/{token}/items/{id}` `{quantity?, notes?}` | `quantity: 0` remove |
| `DELETE /api/v1/carts/{token}/items/{id}` | remove |
| `POST /api/v1/checkout` + header `Idempotency-Key` | 201 `{order_id, order_number, status, payment_status, payment_method, fulfillment_type, total, eta_minutes, tracking_token}` |
| `GET /api/v1/orders/track/{token}` | andamento público sem dados sensíveis |

Checkout: `{cart_token, customer{name, phone, email?}, fulfillment{type: pickup|delivery, zone_id?, address?{street, number, complement?, neighborhood, reference?}}, payment{method: cash|card_on_delivery, change_for?}, notes?}`. Nenhum campo de preço é aceito.

Erros do checkout: `idempotency_key_required` (400), `idempotency_conflict` / `idempotency_in_progress` (409), `cart_not_found` (404), `cart_empty`, `cart_invalid` (`details.items`), `store_closed` (`details.next_opening_at`), `pickup_unavailable`, `delivery_unavailable`, `zone_invalid`, `neighborhood_not_served`, `minimum_order_not_met`, `change_for_too_low`, `validation_failed`.

### Painel do restaurante
| Rota | Permissão |
|---|---|
| `GET /merchant/orders?view=active\|finished\|all\|{status}&branch=&cursor=` | `orders.view` (filtrado pelas unidades do usuário) |
| `GET /merchant/orders/{id}` | `orders.view` + acesso à unidade |
| `PATCH /merchant/orders/{id}/status` `{status, eta_minutes?, reason?, note?}` | `orders.manage`; 409 `invalid_transition`; 422 `reason_required` |
| `GET /merchant/branches/{id}/delivery` | `branches.view` |
| `PUT /merchant/branches/{id}/delivery` `{pickup_enabled, delivery_enabled, pickup_eta_minutes, zones[{id?, name, neighborhoods[], fee, minimum_order?, free_above?, eta_minutes, active?}]}` | `branches.manage` |

`GET /storefront` agora inclui em cada unidade `fulfillment{pickup_enabled, delivery_enabled, pickup_eta_minutes, zones[]}` (só zonas ativas).

## Implementado na Sprint 3 — Pagamento

### Storefront e checkout
- `GET /storefront` ganhou `payment_methods[{method, label, online}]`; `pix` e `online_card` só aparecem com o Mercado Pago conectado.
- `POST /checkout`: `payment.method` aceita também `pix` (exige `customer.email`, pedido do Mercado Pago) e `online_card`. Pedido online nasce `awaiting_payment` e **não aparece no painel** até o pagamento ser confirmado. Resposta ganhou `payment_required` e `payment_expires_at`. A cobrança é criada no provedor logo depois do commit; se falhar, o pedido existe e o acompanhamento oferece gerar de novo. Erro novo: `payment_method_unavailable` (422).
- `GET /orders/track/{token}` ganhou `payment` (online): `{status, amount, expires_at, payable, needs_start, rejected, pix{qr_code, qr_code_base64, ticket_url}|null, checkout_url|null}`. Sem IDs do provedor.
- `POST /orders/track/{token}/payment` (12/min por host+IP): gera a cobrança se ainda não existe ou consulta o provedor ("Já paguei"). Corpo é ignorado. 409 `payment_not_pending`.

### Webhook
`POST /webhooks/mercadopago` no **host do restaurante** (`notification_url` enviada em cada cobrança quando o host é HTTPS, com `?source_news=webhooks`). 120/min por host.
1. Com assinatura secreta configurada: exige `x-signature` válido (`ts`, `v1` = HMAC-SHA256 de `id:{data.id};request-id:{x-request-id};ts:{ts};`) → senão **401** `invalid_signature`.
2. Só `type=payment` com `data.id` numérico; o resto responde 200 `ignored` sem gravar.
3. Grava em `payment_events` (UNIQUE deduplica → 200 `duplicate`), enfileira `ProcessPaymentEvent`, responde 200 `received`.
4. O job consulta `GET /v1/payments/{id}` com o token **do restaurante do host**; pagamento de outra conta = 404 → `ignored`.

### Painel
| Rota | Permissão |
|---|---|
| `GET /merchant/payments/mercadopago` | `payments.manage` (só proprietário) — `{connected, mode, account_label, account_id, token_hint, has_webhook_secret, verified_at, webhook_url}`; nunca devolve credenciais |
| `PUT /merchant/payments/mercadopago` `{access_token?, webhook_secret?, remove_webhook_secret?}` | `payments.manage` — valida o token em `GET /users/me` (conta do Brasil) antes de gravar; 422 `invalid_provider_credentials` |
| `DELETE /merchant/payments/mercadopago` | `payments.manage` — apaga as credenciais |
| `POST /merchant/orders/{id}/refunds` `{amount?, reason?}` | `payments.refund` (proprietário e gerente) + acesso à unidade; sem `amount` devolve tudo o disponível. 409 `nothing_to_refund`, 422 `refund_amount_invalid`, 502 `payment_provider_unavailable` (falha registrada em `refunds`) |
| `GET /merchant/orders…` | resposta ganhou `online_payment{status, amount, amount_refunded, refundable, approved_at, provider_reference, attempts[], refunds[]}`; pedidos `awaiting_payment` não aparecem em nenhum filtro e dão 404 no detalhe |

Cancelar/recusar um pedido pago online dispara o reembolso total automaticamente (job com novas tentativas). O motivo `payment_not_completed` é exclusivo do sistema.

### Chamadas ao Mercado Pago (sem SDK)
`GET /users/me`, `POST /v1/payments` (Pix), `POST /checkout/preferences` (Checkout Pro, sem boleto/lotérica), `GET /v1/payments/{id}`, `GET /v1/payments/search?external_reference=`, `POST /v1/payments/{id}/refunds`. Criações com `X-Idempotency-Key`; timeout de 10 s; logs só com operação e status HTTP.

## Implementado na Sprint 4 — Operação

| Rota | Permissão | Observações |
|---|---|---|
| `GET /merchant/orders/pulse` | `orders.view` | `{version, pending, oldest_pending_at}`; 90/min por usuário. `version` muda a cada alteração em pedido das unidades do usuário. |
| `GET /merchant/orders?view=&from=&to=&q=&payment_method=&branch=&cursor=` | `orders.view` | `from`/`to` em `AAAA-MM-DD` no fuso do estabelecimento (máx. 1 ano); `q` busca nº, nome (LIKE escapado) e celular (≥ 4 dígitos). Pedidos `awaiting_payment` nunca aparecem. |
| `GET /merchant/reports/summary` (mesmos filtros) | `reports.view` | `{orders, completed, cancelled, in_progress, revenue, average_ticket, refunded, by_payment_method[]}` — faturamento = concluídos. |
| `GET /merchant/reports/orders.csv` (mesmos filtros + `view`) | `reports.view` | CSV `;` com BOM UTF-8, valores com vírgula, **sem telefone e endereço**; células iniciadas por `= + - @` neutralizadas; 6/min; exportação auditada. |
| `GET /merchant/audit?action=&cursor=` | `audit.view` (só proprietário) | `{id, action, actor{id,name}, entity_type, entity_id, metadata, ip, at}`; filtro por prefixo da ação. |

Toda escrita bem-sucedida do painel gera registro de auditoria automático (`AuditMutations`): ação = nome da rota sem `merchant.` (ex.: `products.update`), entidade e nomes dos registros afetados — nunca o corpo da requisição.

## Implementado na Sprint 5 — Piloto

### Painel SerTec (host admin, sessão de plataforma; escrita = superadmin)
| Rota | Observações |
|---|---|
| `GET /admin/tenants?q=&status=&cursor=` | lista com host principal, plano, Mercado Pago (test/live) e pedidos em 30 dias |
| `POST /admin/tenants` `{name, slug, owner_name, owner_email, plan?, timezone?, activate}` | cria tenant + subdomínio verificado + Matriz + dono; `owner_password` só na resposta (null se o e-mail já tinha conta). 409 `slug_taken`, 422 `slug_invalid`, `plan_not_found` |
| `GET /admin/tenants/{id}` | ficha: dono, domínios, unidades, equipe, pedidos, Mercado Pago (sem segredos) |
| `PATCH /admin/tenants/{id}` `{name?, status?, plan?}` | cancelado não volta; auditado como `tenant.updated` com antes/depois |
| `POST /admin/tenants/{id}/owner-password` | nova senha temporária (uma vez) e fim das sessões do dono |
| `POST /admin/tenants/{id}/domains` `{hostname}` | domínio próprio: devolve `txt_name`, `txt_value`, `cname_target`; 422 `domain_invalid`/`domain_reserved`, 409 `domain_taken` |
| `POST …/domains/{d}/token` · `…/verify` · `…/primary` · `DELETE …/domains/{d}` | novo token; verificação por TXT (`domain_not_verified`); principal só se verificado; não remove o principal nem o da plataforma |
| `GET /admin/audit?tenant=&action=&scope=platform` | trilha de toda a plataforma |
| `GET /admin/plans` | planos |

### Painel do restaurante
| Rota | Permissão | Observações |
|---|---|---|
| `GET/POST /merchant/staff`, `PATCH/DELETE /merchant/staff/{user}`, `POST /merchant/staff/{user}/password` | `staff.manage` | funções gerente/atendente/cozinha (nunca proprietário); ninguém altera o próprio vínculo; proprietários protegidos; senha só é criada/redefinida para conta **exclusiva** do estabelecimento (409 `shared_account`); desativar/redefinir encerra sessões |
| `POST /merchant/me/password` `{current_password, password, password_confirmation}` | qualquer membro | mín. 10 com letras e números; encerra as outras sessões |
| `PATCH /merchant/branches/{id}` `{name?, phone?, address?{street, number, neighborhood, city, state, zip}, active?}` | `branches.manage` | telefone normalizado; 409 `last_branch` |
| `POST /merchant/branches` | `branches.manage` + plano `multi_branch` | |
| `GET /merchant/privacy/customer?phone=` · `POST /merchant/privacy/customer/erase {phone}` | `settings.update` | LGPD: relatório e anonimização (409 `order_in_progress`); auditoria só com hash do telefone |

### Infraestrutura
- `GET /__storefront` (qualquer host de restaurante, usado pelo Caddy): HTML do cardápio com título, descrição, canonical, `theme-color` e Open Graph do restaurante; 404 `noindex` para host desconhecido.
- `GET /api/v1/internal/tls-ask?domain=` (só rede interna): 200 para o host admin e domínios verificados — autorização do certificado sob demanda.
- `GET /api/v1/internal/health` (só rede interna): `{ok, checks{database, cache, queue, scheduler}}` 200/503. Externo: `/up`.

## Implementado na Sprint 6 — Promoções e cupons

### Público
- `GET /menus` e `GET /products/{id}`: produtos ganham `promo_price_from` e `promotion{id, percent_bp, label, featured, ends_at}`. O carrinho devolve `original_unit_price` e `promotion_label` por item. O preço cobrado continua sendo calculado no servidor.
- `GET /storefront`: `announcement{text, link, link_label, starts_at, ends_at}` quando há faixa vigente.
- `POST /carts/{token}/coupon` `{code, phone?}` → `{code, label, description, discount_type, discount, free_delivery}`. É só prévia: as regras por cliente só valem com `phone`.
- `POST /checkout` aceita `coupon`; a resposta ganha `discount_total`. Erros:
  - `coupon_invalid`: inexistente, inativo, fora do período ou plano sem cupons;
  - `coupon_min_subtotal` (com `details.min_subtotal`);
  - `coupon_exhausted`;
  - `coupon_customer_limit`;
  - `coupon_first_order_only`;
  - `payment_method_unavailable`: pagamento online com total zero.
- `GET /vitrine/promocoes.json`: feed **contrato v1** com `versao`, `atualizadoEm`, `loja.aberta`, `faixa{texto, link, rotuloLink, inicio, fim}` e `promocoes[{id, titulo, descricao, imagem, imagemAlt, precoDe, precoPor, selo, url, inicio, fim, destaque}]`.
  - Datas em ISO 8601 com o fuso do estabelecimento; destaques primeiro; no máximo 20 itens.
  - `url` abre o produto no cardápio (`/?produto=ID`).
  - Cabeçalhos: `Access-Control-Allow-Origin` só para as origens cadastradas (correspondência exata), `Vary: Origin`, `Cache-Control: public, max-age=60`.

### Painel (`marketing.manage`, proprietário e gerente)
| Rota | Observações |
|---|---|
| `GET/POST /merchant/promotions`, `PATCH/DELETE /merchant/promotions/{id}` | `{product_id, percent?, promo_price?, starts_at?, ends_at?, featured?, badge?, active?}`; `promo_price` vira percentual sobre o "a partir de"; 409 `promotion_overlap` |
| `GET/POST /merchant/coupons`, `PATCH/DELETE /merchant/coupons/{id}` | exige o recurso de plano `coupons`; código único (maiúsculas); 409 `coupon_used` ao apagar cupom já usado |
| `GET/PUT /merchant/storefront-feed` | `{announcement{text, link(https), link_label, starts_at, ends_at}, feed_origins[https://host]}` → inclui `feed_url` |


## Implementado na Sprint 7 — Clientes e WhatsApp

### Público (host do tenant, `throttle:phone-lookup`: 10/min e 60/h por IP, 20/h por celular)

| Método | Rota | Corpo | Resposta |
|---|---|---|---|
| POST | `/customers/lookup` | `{phone}` | `{known, first_name, saved_address: {street, number: "•••", neighborhood} \| null}` — nunca número, complemento, referência ou sobrenome |
| POST | `/orders/lookup` | `{phone}` | lista dos pedidos das últimas 24 h: `{number, placed_at, status, status_label, fulfillment_type, total, branch}` — sem nome nem endereço |

### Checkout

- `fulfillment.use_saved_address: true` (entrega): dispensa `fulfillment.address`; o servidor usa o endereço completo do último pedido com entrega daquele celular. Sem endereço salvo: 422 `saved_address_missing`.

### Idioma

- Mensagens de validação em português (`backend/lang/pt_BR`); `APP_LOCALE` padrão `pt_BR`.
- Senha nova (`POST merchant/me/password`): mínimo de 6 caracteres, com 1 letra maiúscula e 1 caractere especial (`PasswordPolicy`).

## Implementado na Sprint 8 — Cardápio

- **Produto** (`merchant/products`):
  - `kind` (`item` | `combo`);
  - `available_days` (0–6, vazio = todos), `available_from` / `available_until` (`HH:MM`);
  - `combo_slots[] {id?, name, quantity, product_ids[]}` (obrigatório em combo).
- **Resposta** do produto: `availability` (rótulo) e `combo_slots`.
- **Cardápio público** (`menus`, `products/{id}`):
  - `kind`, `availability` e `compare_at` (combo);
  - `available` considera dia e horário;
  - opções ganham `available` (itens de combo);
  - `promotion.type`, `buy_quantity` e `pay_quantity`.
- **Promoções** (`merchant/promotions`): `type: "bundle"` com `buy_quantity` (2–20) e `pay_quantity` (< leve).
- **Carrinho:** `discounts[] {label, amount}`, `discount_total` e `net_subtotal`.
- **Pedido:** linha `promotion` em `totals`.
- **Erros:** fora do dia ou horário, 422 `invalid_selection` com `details.reason = product_not_available_now`; item de combo indisponível, `option_invalid`.

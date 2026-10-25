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

# Modelo de dados inicial v0.1

## Convenções
PK bigint unsigned interno; ULID público onde aplicável. Dinheiro DECIMAL(12,2), timestamps UTC, timezone do tenant para exibição. `tenant_id` indexado em todas as tabelas de negócio. FKs compostas para evitar referências cruzadas; soft deletes apenas quando adequados. Segredos cifrados.

## SaaS
- tenants(id, name, slug UNIQUE, status, timezone, created_at)
- tenant_domains(id, tenant_id, hostname UNIQUE, verified_at, is_primary)
- branches(id, tenant_id, name, address_json, phone, active)
- users(id, name, email UNIQUE, password, active)
- tenant_users(id, tenant_id, user_id, role_id, branch_id NULL, UNIQUE(tenant_id,user_id,branch_id))
- roles(id, tenant_id NULL, name); permissions(id, key UNIQUE); role_permissions(role_id, permission_id)
- plans(id, name, monthly_price, features_json); subscriptions(id, tenant_id, plan_id, status, period_end)
- audit_logs(id, tenant_id NULL, actor_user_id NULL, action, entity_type, entity_id, metadata_json, created_at)

## Catálogo
- menus(id, tenant_id, branch_id NULL, name, active)
- categories(id, tenant_id, menu_id, name, sort_order)
- products(id, tenant_id, name, description, image_key, active)
- menu_products(id, tenant_id, menu_id, category_id, product_id, sort_order)
- product_variants(id, tenant_id, product_id, name, price DECIMAL(12,2), active)
- option_groups(id, tenant_id, name, min_select, max_select, required)
- options(id, tenant_id, option_group_id, name, price_delta DECIMAL(12,2), active)
- product_option_groups(id, tenant_id, product_id, option_group_id)
- branch_product_availability(id, tenant_id, branch_id, product_id, available)

## Compras
- customers(id, tenant_id, name, phone, email NULL)
- customer_addresses(id, tenant_id, customer_id, address_json)
- carts(id, tenant_id, branch_id, public_token UNIQUE, customer_id NULL, expires_at)
- cart_items(id, tenant_id, cart_id, product_variant_id, quantity, selections_json)
- orders(id, tenant_id, branch_id, customer_id NULL, public_ulid UNIQUE, tracking_token_hash, status, payment_status, fulfillment_type, subtotal, discount_total, delivery_fee, total, address_snapshot_json, placed_at)
- order_items(id, tenant_id, order_id, product_id NULL, product_name_snapshot, variant_name_snapshot, unit_price, quantity, line_total)
- order_item_options(id, tenant_id, order_item_id, option_name_snapshot, unit_price, quantity)
- order_status_history(id, tenant_id, order_id, old_status, new_status, actor_id NULL, created_at)
- payments(id, tenant_id, order_id, provider, external_id, amount, status, idempotency_key, UNIQUE(provider,external_id))
- payment_events(id, provider, external_event_id, payload_json, processed_at, UNIQUE(provider,external_event_id))
- refunds(id, tenant_id, payment_id, external_id, amount, status)
- delivery_zones(id, tenant_id, branch_id, name, fee, minimum_order)
- coupons(id, tenant_id, code, discount_type, value, active)
- coupon_redemptions(id, tenant_id, coupon_id, order_id, amount)

## Integrações
- integration_connections(id, tenant_id, provider, encrypted_credentials, status)
- notification_jobs(id, tenant_id, order_id NULL, channel, template, status, scheduled_at)
- notification_attempts(id, notification_job_id, status, response_json, created_at)

## Índices e restrições obrigatórios
UNIQUE(tenant_id, branch_id, order_number) para numeração visível; UNIQUE(tenant_id, code) em cupons; índices (tenant_id,status,created_at) em orders; índices de tenant nas FKs. A estrutura acima é lógica: detalhar colunas, constraints e migrations antes da implementação.

## Implementado (Sprints 0 e 1)
Ver `backend/database/migrations` e as decisões D14 e D16–D22 em `docs/ARCHITECTURE.md`. Diferenças em relação ao modelo lógico acima:
- `menu_products` sem `menu_id` (vem da categoria); `option_groups` com `type`, `free_quantity` e `pricing_rule`; obrigatoriedade = `min_select > 0`.
- Novas: `option_variant_prices` (preço do sabor por tamanho), `branch_opening_hours`, `branch_schedule_exceptions`.
- `tenants` ganhou `description` e `brand_color`; `products` usa soft delete.
- Sprint 2: `carts.token_hash` (não `public_token`), `cart_items` com `product_id`; `orders` com `public_id`, `order_number`, `payment_method`, `change_for`, snapshots de cliente/endereço/zona e datas de aceite/conclusão/cancelamento; novas `order_totals`, `order_sequences`, `idempotency_keys`; `delivery_zones` com lista de bairros, `free_above` e `eta_minutes`; `branches` com `pickup_enabled`, `delivery_enabled`, `pickup_eta_minutes`. Cupons ficam para as próximas sprints.
- Sprint 3: `integration_connections` com `credentials` criptografado (APP_KEY), `mode` test/live, `account_id` (conta recebedora, conferida em cada pagamento), `token_hint` (4 últimos caracteres) e UNIQUE `(tenant_id, provider)`. `payments` com `public_id` (= `external_reference` e chave de idempotência no provedor), `method`, `external_id`, `preference_id`, `amount`, `amount_refunded`, `status` próprio (`pending`, `in_review`, `approved`, `rejected`, `cancelled`, `expired`, `refunded`, `partially_refunded`, `charged_back`, `mismatch`), dados do Pix (`pix_qr_code`, `pix_qr_base64`, `ticket_url`), `checkout_url`, `expires_at`, UNIQUE `(provider, external_id)`. `payment_events` (caixa de entrada) **com** `tenant_id` (o webhook chega no host do restaurante) e só campos mínimos (`topic`, `action`, `resource_id`, `live_mode`, `signature_valid`) — nunca o corpo inteiro; UNIQUE `(provider, external_event_id)`. `refunds` com `public_id` (chave de idempotência), `requested_by_user_id` e `last_error`. Novo status de pedido `awaiting_payment` e motivo `payment_not_completed`.

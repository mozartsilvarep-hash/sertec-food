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

<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Audit\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditoria automática do painel: toda escrita bem-sucedida (POST, PUT,
 * PATCH, DELETE com resposta 2xx) vira um registro com o nome da rota, o
 * registro afetado e o ID devolvido — sem corpo da requisição (nada de
 * preços digitados, tokens ou dados pessoais em metadata).
 *
 * Rotas que já registram algo mais rico ficam de fora (ver SKIP).
 */
class AuditMutations
{
    private const SKIP = [
        'merchant.auth.login', 'merchant.auth.logout',
        'merchant.orders.status', 'merchant.orders.refunds.store',
        'merchant.payments.mercadopago.update', 'merchant.payments.mercadopago.destroy',
        'merchant.settings.update',
        'merchant.me.password', 'merchant.staff.store', 'merchant.staff.update',
        'merchant.staff.destroy', 'merchant.staff.password',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;
        if ($request->isMethodSafe() || ! $route instanceof Route || $name === null || in_array($name, self::SKIP, true) || ! $response->isSuccessful()) {
            return $response;
        }

        $entity = null;
        $refs = [];
        foreach ($route->parameters() as $key => $value) {
            if ($value instanceof Model) {
                $entity ??= $value;
                $refs[$key] = (string) ($value->getAttribute('public_id') ?? $value->getKey());
                if (is_string($value->getAttribute('name'))) {
                    $refs[$key.'_name'] = mb_substr($value->getAttribute('name'), 0, 80);
                }
            }
        }

        if ($entity === null && $response instanceof JsonResponse) {
            $id = $response->getData(true)['data']['id'] ?? null;
            if (is_string($id)) {
                $refs['created_id'] = $id;
            }
            $label = $response->getData(true)['data']['name'] ?? null;
            if (is_string($label)) {
                $refs['created_name'] = mb_substr($label, 0, 80);
            }
        }

        $this->audit->record(substr($name, strlen('merchant.')), $entity, $refs);

        return $response;
    }
}

<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permission:branches.view')
 */
class RequirePermission
{
    public function __construct(private readonly TenantAccess $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // "a|b" = qualquer uma das permissões.
        $access = $user instanceof User ? $this->access->for($user) : null;
        if ($access === null || ! collect(explode('|', $permission))->contains(fn (string $p) => $access->can($p))) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}

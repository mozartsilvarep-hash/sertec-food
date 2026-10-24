<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlaciona logs, auditoria e respostas de erro. Aceita X-Request-Id do
 * proxy (Nginx) se tiver formato seguro; senão gera um ULID.
 */
class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) ? $incoming : (string) Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function current(?Request $request = null): ?string
    {
        $request ??= request();
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}

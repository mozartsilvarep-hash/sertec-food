<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlaciona logs, auditoria e respostas de erro. Prefere o ID gerado pelo
 * Nginx (parâmetro FastCGI SERTEC_REQUEST_ID); depois X-Request-Id do
 * cliente, se tiver formato seguro; senão gera um ULID.
 *
 * O Nginx NÃO sobrescreve o cabeçalho X-Request-Id: o Mercado Pago assina o
 * webhook com ele (manifest "request-id:...").
 */
class AssignRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::ulid();
        foreach ([(string) $request->server('SERTEC_REQUEST_ID', ''), (string) $request->headers->get(self::HEADER, '')] as $candidate) {
            if (preg_match('/^[A-Za-z0-9-]{8,64}$/', $candidate)) {
                $id = $candidate;
                break;
            }
        }

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

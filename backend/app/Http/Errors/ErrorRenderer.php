<?php

namespace App\Http\Errors;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Formato único de erro da API: {code, message, details, request_id}.
 * Nunca expõe mensagem interna, stack trace ou dados de outro tenant.
 */
final class ErrorRenderer
{
    private const DEFAULTS = [
        400 => ['bad_request', 'Requisição inválida.'],
        401 => ['unauthenticated', 'Autenticação necessária.'],
        403 => ['forbidden', 'Você não tem permissão para esta ação.'],
        404 => ['not_found', 'Recurso não encontrado.'],
        405 => ['method_not_allowed', 'Método não permitido.'],
        419 => ['csrf_token_mismatch', 'Sessão expirada. Recarregue a página.'],
        422 => ['validation_failed', 'Dados inválidos.'],
        429 => ['too_many_requests', 'Muitas tentativas. Aguarde e tente novamente.'],
        500 => ['internal_error', 'Erro interno. Tente novamente em instantes.'],
        503 => ['service_unavailable', 'Serviço temporariamente indisponível.'],
    ];

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $details] = self::describe($e);

        $headers = [];
        if ($e instanceof HttpExceptionInterface) {
            $headers = array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining']));
        }

        return new JsonResponse([
            'code' => $code,
            'message' => $message,
            'details' => $details,
            'request_id' => AssignRequestId::current($request),
        ], $status, $headers);
    }

    /**
     * @return array{0:int,1:string,2:string,3:array<string,mixed>|null}
     */
    private static function describe(Throwable $e): array
    {
        if ($e instanceof ApiException) {
            return [$e->getStatusCode(), $e->errorCode, $e->getMessage(), $e->details];
        }

        if ($e instanceof ValidationException) {
            return [422, 'validation_failed', self::DEFAULTS[422][1], ['fields' => $e->errors()]];
        }

        if ($e instanceof AuthenticationException) {
            return [401, ...self::defaults(401), null];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return [$status, ...self::defaults($status), null];
        }

        return [500, ...self::defaults(500), null];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function defaults(int $status): array
    {
        return self::DEFAULTS[$status] ?? ($status >= 500 ? self::DEFAULTS[500] : ['http_'.$status, 'Erro na requisição.']);
    }
}

<?php

namespace App\Modules\Payments\Services;

/**
 * Verificação do cabeçalho x-signature do Mercado Pago, no mesmo formato do
 * SDK oficial (WebhookSignatureValidator):
 *
 *   x-signature: ts=1704908010,v1=<hmac-sha256 hex>
 *   manifest   = "id:{data.id};request-id:{x-request-id};ts:{ts};"
 *                (partes ausentes são omitidas)
 *
 * A documentação pede data.id em minúsculas quando alfanumérico e o SDK usa
 * o valor como recebido; aceitamos qualquer um dos dois (ambos exigem o segredo).
 * Sem tolerância de horário: reenvios do provedor reaproveitam a assinatura e
 * replays são inofensivos (deduplicação + consulta autoritativa na API).
 */
final class WebhookSignature
{
    public static function valid(?string $header, ?string $requestId, ?string $dataId, string $secret): bool
    {
        if ($secret === '' || $header === null || trim($header) === '') {
            return false;
        }

        $ts = null;
        $hash = null;
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = [trim($pair[0]), trim($pair[1])];
            if ($key === 'ts') {
                $ts = $value;
            } elseif ($key === 'v1') {
                $hash = strtolower($value);
            }
        }

        if ($ts === null || ! ctype_digit($ts) || $hash === null || ! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            return false;
        }

        $requestId = self::clean($requestId);
        $dataId = self::clean($dataId);

        foreach (array_unique([$dataId, $dataId === null ? null : strtolower($dataId)]) as $id) {
            if (hash_equals(hash_hmac('sha256', self::manifest($id, $requestId, $ts), $secret), $hash)) {
                return true;
            }
        }

        return false;
    }

    public static function manifest(?string $dataId, ?string $requestId, string $ts): string
    {
        return ($dataId !== null ? "id:{$dataId};" : '')
            .($requestId !== null ? "request-id:{$requestId};" : '')
            ."ts:{$ts};";
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

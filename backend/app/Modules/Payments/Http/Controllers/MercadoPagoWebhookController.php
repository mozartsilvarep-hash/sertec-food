<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Jobs\ProcessPaymentEvent;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Services\WebhookSignature;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/v1/webhooks/mercadopago — no host do restaurante (o tenant vem do
 * Host, como em todo o resto). Fluxo: verifica assinatura → grava na caixa de
 * entrada (UNIQUE deduplica) → enfileira → responde 200 em milissegundos.
 *
 * O corpo da notificação NUNCA é usado como fonte do status: o job consulta a
 * API do provedor com o token do restaurante.
 */
class MercadoPagoWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $connection = IntegrationConnection::activeFor(Provider::MercadoPago);
        if ($connection === null) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $query = self::rawQuery($request);
        $topic = (string) ($request->input('type') ?? $query['type'] ?? $query['topic'] ?? '');
        $dataId = $query['data.id'] ?? $query['data_id'] ?? $request->input('data.id') ?? $query['id'] ?? null;
        $dataId = is_scalar($dataId) ? mb_substr((string) $dataId, 0, 64) : null;

        $secret = $connection->webhookSecret();
        $signatureValid = $secret !== null && WebhookSignature::valid(
            $request->header('x-signature'),
            $request->header('x-request-id'),
            $dataId,
            $secret,
        );

        // Com segredo configurado, notificação sem assinatura válida é recusada.
        if ($secret !== null && ! $signatureValid) {
            Log::notice('payments.webhook_bad_signature', ['host' => $request->getHost()]);

            return new JsonResponse(['code' => 'invalid_signature', 'message' => 'Assinatura inválida.'], 401);
        }

        if ($topic !== 'payment' || $dataId === null || ! preg_match('/^\d{1,30}$/', $dataId)) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $notificationId = $request->input('id');
        $eventId = is_scalar($notificationId) && (string) $notificationId !== ''
            ? 'n:'.mb_substr((string) $notificationId, 0, 90)
            : 'h:'.hash('sha256', implode('|', [$topic, $dataId, (string) $request->input('action'), (string) $request->header('x-request-id')]));

        try {
            $event = PaymentEvent::query()->create([
                'provider' => Provider::MercadoPago,
                'external_event_id' => $eventId,
                'topic' => $topic,
                'action' => is_string($request->input('action')) ? mb_substr($request->input('action'), 0, 60) : null,
                'resource_id' => $dataId,
                'live_mode' => is_bool($request->input('live_mode')) ? $request->input('live_mode') : null,
                'signature_valid' => $signatureValid,
                'status' => PaymentEvent::RECEIVED,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return new JsonResponse(['status' => 'duplicate']);
            }
            throw $e;
        }

        try {
            ProcessPaymentEvent::dispatch($event->id);
        } catch (Throwable $e) {
            // Fila síncrona (dev/testes): o recebimento já está gravado e a
            // reconciliação reprocessa; o provedor não precisa reenviar.
            Log::warning('payments.webhook_processing_failed', ['event' => $event->id, 'error' => class_basename($e)]);
        }

        return new JsonResponse(['status' => 'received']);
    }

    /**
     * O PHP troca "." por "_" em $_GET ("data.id" vira "data_id"); lemos a
     * query string crua para manter o nome usado na assinatura.
     *
     * @return array<string, string>
     */
    private static function rawQuery(Request $request): array
    {
        $out = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[rawurldecode($key)] = rawurldecode(str_replace('+', ' ', $value));
        }

        return $out;
    }
}

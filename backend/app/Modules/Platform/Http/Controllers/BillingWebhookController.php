<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Payments\Services\WebhookSignature;
use App\Modules\Platform\Billing\Jobs\ProcessBillingEvent;
use App\Modules\Platform\Billing\PlatformMercadoPago;
use App\Modules\Platform\Models\BillingEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /api/v1/billing/webhooks/mercadopago — no host admin, conta da SerTec.
 * Mesmo desenho do webhook dos restaurantes: assinatura → caixa de entrada
 * deduplicada → fila. O status vem da API, nunca do corpo.
 */
class BillingWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! PlatformMercadoPago::configured()) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $query = [];
        foreach (explode('&', (string) $request->server('QUERY_STRING', '')) as $pair) {
            if ($pair !== '') {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $query[rawurldecode($k)] = rawurldecode(str_replace('+', ' ', $v));
            }
        }
        $topic = (string) ($request->input('type') ?? $query['type'] ?? $query['topic'] ?? '');
        $dataId = $query['data.id'] ?? $request->input('data.id') ?? $query['id'] ?? null;
        $dataId = is_scalar($dataId) ? mb_substr((string) $dataId, 0, 64) : null;

        $secret = PlatformMercadoPago::webhookSecret();
        $valid = $secret !== null && WebhookSignature::valid($request->header('x-signature'), $request->header('x-request-id'), $dataId, $secret);
        if ($secret !== null && ! $valid) {
            Log::notice('billing.webhook_bad_signature');

            return new JsonResponse(['code' => 'invalid_signature', 'message' => 'Assinatura inválida.'], 401);
        }
        if ($topic !== 'payment' || $dataId === null || ! preg_match('/^\d{1,30}$/', $dataId)) {
            return new JsonResponse(['status' => 'ignored']);
        }

        $nid = $request->input('id');
        $eventId = is_scalar($nid) && (string) $nid !== '' ? 'n:'.mb_substr((string) $nid, 0, 90)
            : 'h:'.hash('sha256', $topic.'|'.$dataId.'|'.(string) $request->input('action').'|'.(string) $request->header('x-request-id'));

        try {
            $event = BillingEvent::query()->create(['external_event_id' => $eventId, 'resource_id' => $dataId, 'signature_valid' => $valid]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return new JsonResponse(['status' => 'duplicate']);
            }
            throw $e;
        }

        try {
            ProcessBillingEvent::dispatch($event->id);
        } catch (Throwable $e) {
            Log::warning('billing.webhook_processing_failed', ['event' => $event->id, 'error' => class_basename($e)]);
        }

        return new JsonResponse(['status' => 'received']);
    }
}

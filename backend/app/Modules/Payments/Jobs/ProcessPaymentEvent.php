<?php

namespace App\Modules\Payments\Jobs;

use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Services\PaymentSync;
use App\Modules\Tenancy\Jobs\TenantJob;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Processa uma notificação da caixa de entrada: consulta o pagamento na API
 * do provedor e aplica. Falhas voltam para a fila; a reconciliação periódica
 * cobre o que ainda assim escapar.
 */
class ProcessPaymentEvent extends TenantJob
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public int $eventId)
    {
        parent::__construct();
    }

    public function handle(PaymentSync $sync): void
    {
        $event = PaymentEvent::query()->find($this->eventId);
        if ($event === null || $event->status === PaymentEvent::PROCESSED || $event->resource_id === null) {
            return;
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        try {
            $payment = $sync->handleRemotePaymentId($event->resource_id);
        } catch (GatewayException $e) {
            // 404 com o token deste restaurante: o pagamento não é da conta dele.
            if ($e->httpStatus === 404) {
                $event->forceFill(['status' => PaymentEvent::IGNORED, 'error' => 'not_found_for_account', 'processed_at' => Carbon::now()])->save();

                return;
            }
            $event->forceFill(['status' => PaymentEvent::FAILED, 'error' => mb_substr($e->reason, 0, 120)])->save();
            throw $e;
        } catch (Throwable $e) {
            $event->forceFill(['status' => PaymentEvent::FAILED, 'error' => mb_substr(class_basename($e), 0, 120)])->save();
            throw $e;
        }

        $event->forceFill([
            'status' => $payment === null ? PaymentEvent::IGNORED : PaymentEvent::PROCESSED,
            'error' => null,
            'processed_at' => Carbon::now(),
        ])->save();
    }
}

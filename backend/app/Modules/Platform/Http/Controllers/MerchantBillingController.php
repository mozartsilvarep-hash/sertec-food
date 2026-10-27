<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Billing\BillingService;
use App\Modules\Platform\Billing\InvoicePresenter;
use App\Modules\Platform\Billing\PlatformMercadoPago;
use App\Modules\Platform\Models\Invoice;
use App\Modules\Platform\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel do restaurante → Assinatura: plano, faturas e pagamento por Pix na
 * conta da SerTec. Só o proprietário (billing.manage).
 */
class MerchantBillingController
{
    public function show(BillingService $billing): JsonResponse
    {
        $sub = self::subscription();
        // Sem fatura em aberto, gera a do próximo período se já estiver na janela.
        if ($sub !== null) {
            $billing->ensureUpcoming($sub);
        }
        $invoices = Invoice::query()->orderByDesc('period_start')->limit(24)->get();

        return new JsonResponse(['data' => [
            'subscription' => InvoicePresenter::subscription($sub),
            'pix_available' => PlatformMercadoPago::configured(),
            'invoices' => $invoices->map(fn (Invoice $i) => InvoicePresenter::invoice($i, withPix: true))->values(),
        ]]);
    }

    public function pix(Request $request, Invoice $invoice, BillingService $billing): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invoice = $billing->pix($invoice, $user->email, $user->name, $request->isSecure());

        return new JsonResponse(['data' => InvoicePresenter::invoice($invoice, withPix: true)]);
    }

    /** Situação da fatura; com Pix pendente, confere no Mercado Pago (no máximo a cada 10 s). */
    public function status(Invoice $invoice, BillingService $billing): JsonResponse
    {
        if ($invoice->status === Invoice::OPEN && $invoice->mp_payment_id !== null
            && ($invoice->synced_at === null || $invoice->synced_at->lt(now()->subSeconds(10)))) {
            $invoice = $billing->syncPayment($invoice);
        }

        return new JsonResponse(['data' => InvoicePresenter::invoice($invoice, withPix: true)]);
    }

    public static function subscription(): ?Subscription
    {
        return Subscription::query()->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE, Subscription::STATUS_TRIALING])
            ->with('plan')->latest('id')->first();
    }
}

<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Ordering\Privacy\CustomerPrivacy;
use App\Modules\Ordering\Services\OrderingException;
use App\Modules\Ordering\Support\Phone;
use App\Modules\Platform\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atendimento a titulares (LGPD) pelo restaurante — permissão settings.update.
 * GET  /merchant/privacy/customer?phone=  o que temos sobre esse celular
 * POST /merchant/privacy/customer/erase {phone}  remove os dados pessoais
 */
class PrivacyController
{
    public function show(Request $request, CustomerPrivacy $privacy, AuditLogger $audit): JsonResponse
    {
        $phone = $this->phone($request);
        $report = $privacy->report($phone);
        $audit->record('privacy.report_viewed', null, ['phone_hash' => hash('sha256', $phone), 'orders' => count($report['orders'])]);

        return new JsonResponse(['data' => $report]);
    }

    public function erase(Request $request, CustomerPrivacy $privacy, AuditLogger $audit): JsonResponse
    {
        $phone = $this->phone($request);
        $count = $privacy->anonymizeCustomer($phone);
        // Nunca grava o telefone em claro na trilha.
        $audit->record('privacy.customer_erased', null, ['phone_hash' => hash('sha256', $phone), 'orders' => $count]);

        return new JsonResponse(['data' => ['orders_anonymized' => $count]]);
    }

    private function phone(Request $request): string
    {
        $request->validate(['phone' => ['required', 'string', 'max:25']]);

        return Phone::normalize((string) $request->input('phone'))
            ?? throw OrderingException::make('validation_failed', 'Informe o celular com DDD.', ['fields' => ['phone' => ['Informe o celular com DDD.']]]);
    }
}

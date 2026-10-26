<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Ordering\Services\CustomerDirectory;
use App\Modules\Ordering\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /customers/lookup — o cliente digita o celular no checkout.
 * POST /orders/lookup    — "Acompanhar pedido" pelo celular.
 *
 * Públicos (o tenant vem do Host) e com limite apertado por IP e por celular
 * (throttle:phone-lookup), para não virar ferramenta de varredura de números.
 * Respostas com dados mínimos: ver CustomerDirectory.
 */
class CustomerLookupController
{
    public function __construct(private readonly CustomerDirectory $directory) {}

    public function customer(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->directory->publicLookup($this->phone($request))]);
    }

    public function orders(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->directory->recentOrders($this->phone($request))]);
    }

    private function phone(Request $request): string
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:25', function (string $attr, mixed $value, \Closure $fail) {
                if (! is_string($value) || Phone::normalize($value) === null) {
                    $fail('Informe um celular com DDD, ex.: (79) 99999-0000.');
                }
            }],
        ], [], ['phone' => 'celular']);

        return (string) Phone::normalize($data['phone']);
    }
}

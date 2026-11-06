<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Modules\Cash\Models\TabRequest;
use App\Modules\Cash\Tabs\TableOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * QR Code da mesa (público, host do restaurante). O pedido só vale depois
 * que o garçom confirma.
 */
class PublicTableController
{
    public function show(string $token, TableOrderService $orders): JsonResponse
    {
        $table = $orders->table($token)->load('branch');

        return new JsonResponse(['data' => [
            'label' => $table->label,
            'branch' => ['id' => $table->branch?->public_id, 'name' => $table->branch?->name],
        ]]);
    }

    public function store(Request $request, string $token, TableOrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([TabRequest::ORDER, TabRequest::WAITER, TabRequest::BILL])],
            'cart_token' => ['required_if:type,order', 'nullable', 'string', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:200'],
        ], [], ['customer_name' => 'nome', 'notes' => 'observação']);
        $result = $orders->request($orders->table($token), $data['type'], $data['cart_token'] ?? null, $data['customer_name'] ?? null, $data['notes'] ?? null);

        return new JsonResponse(['data' => [
            ...self::present($result['request']),
            // Só na criação: guarda no aparelho para acompanhar.
            'view_token' => $result['token'] !== '' ? $result['token'] : null,
        ]], $result['token'] !== '' ? 201 : 200);
    }

    public function status(Request $request, string $id, TableOrderService $orders): JsonResponse
    {
        return new JsonResponse(['data' => self::present($orders->find($id, (string) $request->query('t', '')))]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(TabRequest $r): array
    {
        return [
            'id' => $r->public_id,
            'type' => $r->type,
            'status' => $r->status,
            'summary' => $r->summary,
            'reason' => $r->reason,
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}

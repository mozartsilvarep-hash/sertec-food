<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Ordering\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Pulso" dos pedidos: um contador por unidade no cache (Redis), incrementado
 * DEPOIS do commit de qualquer mudança em pedido. O painel consulta só o
 * contador a cada poucos segundos e recarrega a lista quando ele muda — quase
 * tempo real sem servidor de WebSocket e sem carga no banco.
 */
final class OrderPulse
{
    public static function key(int $tenantId, int $branchId): string
    {
        return "order-pulse:{$tenantId}:{$branchId}";
    }

    public static function bump(Order $order): void
    {
        $key = self::key($order->tenant_id, $order->branch_id);

        DB::afterCommit(function () use ($key) {
            Cache::add($key, 0, now()->addDays(7));
            Cache::increment($key);
        });
    }

    /**
     * Versão combinada das unidades informadas (muda se qualquer uma mudar).
     *
     * @param  list<int>  $branchIds
     */
    public static function version(int $tenantId, array $branchIds): string
    {
        sort($branchIds);
        $parts = array_map(fn (int $id) => $id.':'.(int) Cache::get(self::key($tenantId, $id), 0), $branchIds);

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }
}

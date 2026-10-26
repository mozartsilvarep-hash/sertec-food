<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderFilters;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\Refund;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resumo do período e exportação CSV (permissão reports.view).
 * Faturamento = pedidos concluídos; reembolsos aparecem à parte.
 */
class ReportController
{
    public function summary(Request $request, TenantAccess $tenantAccess, TenantContext $context): JsonResponse
    {
        $access = $tenantAccess->for($this->user($request));
        $timezone = $context->get()->timezone;
        $base = OrderFilters::query($request, $access, $timezone);

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) AS c')->groupBy('status')->toBase()->pluck('c', 'status');
        $byMethod = (clone $base)->where('status', OrderStatus::Completed)
            ->selectRaw('payment_method, COUNT(*) AS c, SUM(total) AS s')->groupBy('payment_method')->toBase()->get();

        $completed = (int) ($byStatus[OrderStatus::Completed->value] ?? 0);
        $revenueCents = 0;
        $methods = [];
        foreach ($byMethod as $row) {
            $cents = Money::toCents(number_format((float) $row->s, 2, '.', ''));
            $revenueCents += $cents;
            $method = PaymentMethod::from((string) $row->payment_method);
            $methods[] = ['method' => $method->value, 'label' => $method->label(), 'orders' => (int) $row->c, 'total' => Money::format($cents)];
        }

        $refunded = Refund::query()
            ->whereIn('status', [RefundStatus::Approved, RefundStatus::Pending])
            ->whereHas('payment', fn ($p) => $p->whereIn('order_id', (clone $base)->select('id')))
            ->sum('amount');

        return new JsonResponse(['data' => [
            'orders' => array_sum(array_map('intval', $byStatus->all())),
            'completed' => $completed,
            'cancelled' => (int) ($byStatus[OrderStatus::Cancelled->value] ?? 0) + (int) ($byStatus[OrderStatus::Rejected->value] ?? 0),
            'in_progress' => array_sum(array_map(fn (OrderStatus $s) => (int) ($byStatus[$s->value] ?? 0), OrderStatus::active())),
            'revenue' => Money::format($revenueCents),
            'average_ticket' => Money::format($completed > 0 ? intdiv($revenueCents, $completed) : 0),
            'refunded' => Money::format(Money::toCents(number_format((float) $refunded, 2, '.', ''))),
            'by_payment_method' => $methods,
        ]]);
    }

    public function csv(Request $request, TenantAccess $tenantAccess, TenantContext $context, AuditLogger $audit): StreamedResponse
    {
        $access = $tenantAccess->for($this->user($request));
        $timezone = $context->get()->timezone;
        $query = OrderFilters::view(OrderFilters::query($request, $access, $timezone), (string) $request->query('view', 'all'))
            ->with(['branch', 'payments'])->orderBy('placed_at')->orderBy('id');

        $audit->record('reports.orders_exported', null, [
            'from' => $request->query('from'), 'to' => $request->query('to'), 'rows' => (clone $query)->count(),
        ]);

        $filename = 'pedidos-'.($request->query('from') ?: 'inicio').'-a-'.($request->query('to') ?: 'hoje').'.csv';

        $tenant = $context->get();

        // O corpo é gerado depois que os middlewares terminam (o contexto do
        // tenant já foi limpo): reabre o contexto explicitamente.
        return new StreamedResponse(fn () => app(TenantContext::class)->run($tenant, function () use ($query, $timezone) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // BOM: o Excel abre acentos corretamente
            fputcsv($out, ['Número', 'Data', 'Hora', 'Unidade', 'Status', 'Cliente', 'Tipo', 'Bairro', 'Pagamento', 'Situação do pagamento', 'Subtotal', 'Entrega', 'Total', 'Reembolsado'], ';', '"', '');

            $query->chunk(500, function ($orders) use ($out, $timezone) {
                /** @var Order $o */
                foreach ($orders as $o) {
                    $placed = $o->placed_at->copy()->setTimezone($timezone);
                    $refunded = $o->payments->sum(fn ($p) => Money::toCents($p->amount_refunded));
                    fputcsv($out, array_map([self::class, 'cell'], [
                        (string) $o->order_number,
                        $placed->format('d/m/Y'),
                        $placed->format('H:i'),
                        $o->branch->name,
                        $o->status->label(),
                        $o->customer_name,
                        $o->fulfillment_type->value === 'delivery' ? 'Entrega' : 'Retirada',
                        (string) ($o->address_snapshot_json['neighborhood'] ?? ''),
                        $o->payment_method->label(),
                        $o->payment_status->label(),
                        self::br($o->subtotal),
                        self::br($o->delivery_fee),
                        self::br($o->total),
                        self::br(Money::format($refunded)),
                    ]), ';', '"', '');
                }
            });
            fclose($out);
        }), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Neutraliza fórmulas (CSV injection): =, +, -, @, tab e CR no início. */
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private static function br(string $amount): string
    {
        return str_replace('.', ',', $amount);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Identity\Access\MemberAccess;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Filtros do histórico, do resumo e da exportação — uma única implementação
 * para as três telas. Sempre: unidades do usuário e nunca pedidos ainda
 * aguardando pagamento online. Datas no fuso do estabelecimento.
 */
final class OrderFilters
{
    /**
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable|null}
     */
    public static function validate(Request $request, string $timezone): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'max:30'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'branch' => ['nullable', 'string', 'size:26'],
        ]);

        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone)->startOfDay() : null;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone)->endOfDay() : null;
        if ($from !== null && $to !== null && $from->diffInDays($to) > 370) {
            throw OrderingException::make('period_too_long', 'Escolha um período de até 1 ano.');
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @return Builder<Order>
     */
    public static function query(Request $request, MemberAccess $access, string $timezone): Builder
    {
        ['from' => $from, 'to' => $to] = self::validate($request, $timezone);

        $query = Order::query()
            ->where('status', '!=', OrderStatus::AwaitingPayment)
            ->when($access->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $access->branchIds ?? []))
            ->when($from, fn ($q) => $q->where('placed_at', '>=', $from->utc()))
            ->when($to, fn ($q) => $q->where('placed_at', '<=', $to->utc()));

        if ($request->filled('branch')) {
            $query->where('branch_id', PublicIds::one(Branch::class, (string) $request->query('branch'), 'branch')->id);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', (string) $request->query('payment_method'));
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where(function (Builder $w) use ($q) {
                $digits = preg_replace('/\D/', '', $q) ?? '';
                if (preg_match('/^#?\d{1,7}$/', $q)) {
                    $w->orWhere('order_number', (int) ltrim($q, '#'));
                }
                if (strlen($digits) >= 4) {
                    $w->orWhere('customer_phone', 'like', '%'.$digits.'%');
                }
                $w->orWhere('customer_name', 'like', '%'.addcslashes($q, '%_\\').'%');
            });
        }

        return $query;
    }

    /**
     * "active" | "finished" | "all" | um status.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public static function view(Builder $query, string $view): Builder
    {
        return match (true) {
            $view === 'active' => $query->whereIn('status', OrderStatus::active()),
            $view === 'finished' => $query->whereIn('status', [OrderStatus::Completed, OrderStatus::Rejected, OrderStatus::Cancelled]),
            OrderStatus::tryFrom($view) !== null => $query->where('status', $view),
            default => $query,
        };
    }
}

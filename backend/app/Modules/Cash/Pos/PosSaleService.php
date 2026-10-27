<?php

namespace App\Modules\Cash\Pos;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Cash\Services\CashBook;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Ordering\Enums\FulfillmentType;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Enums\PaymentStatus;
use App\Modules\Ordering\Models\Cart;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\IdempotencyKey;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderPayment;
use App\Modules\Ordering\Models\OrderStatusHistory;
use App\Modules\Ordering\Models\OrderTotal;
use App\Modules\Ordering\Services\CartService;
use App\Modules\Ordering\Services\CheckoutService;
use App\Modules\Ordering\Support\Phone;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Models\Branch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Venda do PDV (D78–D80 da Sprint 14). Vira um PEDIDO de verdade: entra nos
 * relatórios, no histórico, na cozinha (se pedido) e no cartão fidelidade.
 *
 * Preço SEMPRE do servidor: os itens do cardápio passam pelo mesmo
 * carrinho/calculadora do site (tamanhos, sabores, adicionais, promoções).
 * Só itens avulsos usam o valor digitado pelo caixa.
 *
 * Pagamento: uma ou mais formas cuja soma é exatamente o total; dinheiro
 * recebido acima da parte em dinheiro vira troco. Cada forma entra no caixa
 * aberto na hora (o PDV exige caixa aberto).
 */
final class PosSaleService
{
    public const MAX_SERVICE_PERCENT = 20;

    public function __construct(
        private readonly CartService $carts,
        private readonly CashBook $book,
        private readonly LoyaltyService $loyalty,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validado por PosSaleRequest
     * @return array{status: int, body: array<string, mixed>}
     */
    public function sell(Branch $branch, User $user, string $key, array $data): array
    {
        $hash = hash('sha256', (string) json_encode($data));
        try {
            return DB::transaction(fn () => $this->run($branch, $user, 'pos:'.$key, $hash, $data));
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                $existing = IdempotencyKey::query()->where('key', 'pos:'.$key)->first();
                if ($existing !== null && hash_equals($existing->request_hash, $hash) && $existing->status_code > 0) {
                    return ['status' => $existing->status_code, 'body' => $existing->response_json];
                }
                throw new ApiException(409, 'idempotency_in_progress', 'Venda em processamento. Aguarde um instante.');
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: int, body: array<string, mixed>}
     */
    private function run(Branch $branch, User $user, string $key, string $hash, array $data): array
    {
        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->request_hash, $hash)) {
                throw new ApiException(409, 'idempotency_conflict', 'Esta venda já foi registrada com outros dados.');
            }

            return ['status' => $existing->status_code, 'body' => $existing->response_json];
        }
        $reservation = IdempotencyKey::query()->create(['key' => $key, 'request_hash' => $hash, 'status_code' => 0, 'response_json' => []]);

        // Caixa aberto é obrigatório: o dinheiro entra nele.
        Branch::query()->whereKey($branch->id)->lockForUpdate()->first();
        if ($this->book->current($branch) === null) {
            throw new ApiException(409, 'cash_closed', 'Abra o caixa desta unidade para vender.');
        }

        // 1. Itens do cardápio: mesmo carrinho e calculadora do site.
        /** @var list<array<string, mixed>> $lines */
        $lines = $data['items'];
        $menuLines = array_values(array_filter($lines, fn (array $l) => ! empty($l['product_id'])));
        $customLines = array_values(array_filter($lines, fn (array $l) => empty($l['product_id'])));

        $view = ['items' => [], 'subtotal_cents' => 0, 'discounts' => [], 'discount_cents' => 0, 'quotes' => []];
        $cart = null;
        if ($menuLines !== []) {
            [$cart] = $this->carts->create($branch);
            foreach ($menuLines as $i => $line) {
                try {
                    /** @var list<array<string, mixed>> $options */
                    $options = $line['options'] ?? [];
                    $this->carts->add($cart, (string) $line['product_id'], (string) $line['variant_id'], $options, (int) $line['quantity'], $line['notes'] ?? null);
                } catch (ApiException $e) {
                    throw new ApiException(422, 'sale_item_invalid', 'Item '.($i + 1).': '.$e->getMessage(), ['item' => $i]);
                }
            }
            /** @var Cart $cart */
            $cart = Cart::query()->with('branch')->whereKey($cart->id)->firstOrFail();
            $view = $this->carts->view($cart);
            if (! $view['valid']) {
                throw new ApiException(422, 'sale_item_invalid', 'Algum item ficou indisponível. Revise a venda.');
            }
        }

        // 2. Itens avulsos (valor do caixa).
        $custom = [];
        foreach ($customLines as $line) {
            $unit = Money::toCents(str_replace(',', '.', (string) $line['unit_price']));
            $qty = (int) $line['quantity'];
            if ($unit <= 0) {
                throw new ApiException(422, 'sale_item_invalid', 'Item avulso sem valor.');
            }
            $custom[] = ['name' => trim((string) $line['description']), 'unit' => $unit, 'qty' => $qty, 'notes' => $line['notes'] ?? null];
        }

        $subtotal = $view['subtotal_cents'] + array_sum(array_map(fn ($c) => $c['unit'] * $c['qty'], $custom));
        if ($subtotal <= 0) {
            throw new ApiException(422, 'sale_empty', 'A venda precisa de ao menos um item com valor.');
        }
        $promotion = $view['discount_cents'];
        $net = $subtotal - $promotion;

        // 3. Cliente (opcional) e prêmio do cartão fidelidade.
        $phone = ! empty($data['customer']['phone']) ? Phone::normalize((string) $data['customer']['phone']) : null;
        if (! empty($data['customer']['phone']) && $phone === null) {
            throw new ApiException(422, 'validation_failed', 'Celular inválido.', ['fields' => ['customer.phone' => ['Informe um celular com DDD.']]]);
        }
        $name = trim((string) ($data['customer']['name'] ?? '')) ?: 'Cliente balcão';

        // 4. Desconto do caixa (R$ ou %), sobre os itens já com promoção.
        $discount = 0;
        if (! empty($data['discount']['value'])) {
            $value = (string) $data['discount']['value'];
            $discount = ($data['discount']['type'] ?? 'amount') === 'percent'
                ? (int) round($net * min(100, (float) str_replace(',', '.', $value)) / 100)
                : Money::toCents(str_replace(',', '.', $value));
            if ($discount < 0 || $discount > $net) {
                throw new ApiException(422, 'sale_discount_invalid', 'Desconto maior que o valor dos itens.');
            }
        }

        $loyalty = null;
        $loyaltyDiscount = 0;
        $loyaltyLabel = 'Cartão fidelidade';
        if (! empty($data['use_loyalty'])) {
            if ($phone === null) {
                throw new ApiException(422, 'loyalty_no_reward', 'Informe o celular do cliente para usar o prêmio.');
            }
            $loyalty = $this->loyalty->reserve($phone, max(0, $net - $discount));
            $loyaltyDiscount = $loyalty['discount_cents'];
            $loyaltyLabel = $loyalty['label'];
        }

        // 5. Taxa de serviço (%), sobre o que sobra depois dos descontos.
        $servicePercent = (float) ($data['service_percent'] ?? 0);
        $service = (int) round(max(0, $net - $discount - $loyaltyDiscount) * $servicePercent / 100);
        $total = $net - $discount - $loyaltyDiscount + $service;

        // 6. Pagamento: soma exata; troco só em dinheiro.
        /** @var list<array{method: string, amount: string}> $payments */
        $payments = $data['payments'] ?? [];
        $parts = [];
        foreach ($payments as $p) {
            $cents = Money::toCents(str_replace(',', '.', (string) $p['amount']));
            if ($cents > 0) {
                $parts[] = ['method' => CashMethod::from($p['method']), 'cents' => $cents];
            }
        }
        $paid = array_sum(array_column($parts, 'cents'));
        if ($total > 0 && $paid !== $total) {
            throw new ApiException(422, 'sale_payment_mismatch', 'As formas de pagamento somam '.Money::br($paid).', mas o total é '.Money::br($total).'.', [
                'total' => Money::format($total), 'paid' => Money::format($paid),
            ]);
        }
        $cashPart = array_sum(array_map(fn ($p) => $p['method'] === CashMethod::Cash ? $p['cents'] : 0, $parts));
        $change = null;
        if (! empty($data['cash_received'])) {
            $received = Money::toCents(str_replace(',', '.', (string) $data['cash_received']));
            if ($received < $cashPart) {
                throw new ApiException(422, 'sale_received_short', 'O dinheiro recebido é menor que a parte em dinheiro.');
            }
            $change = $received - $cashPart;
        }

        // 7. Pedido.
        $now = CarbonImmutable::now();
        $customer = null;
        if ($phone !== null) {
            $customer = Customer::query()->firstOrNew(['phone' => $phone]);
            $customer->fill(['name' => $name, 'last_order_at' => $now])->save();
        }
        $kitchen = ! empty($data['send_to_kitchen']);
        $order = Order::query()->create([
            'branch_id' => $branch->id,
            'channel' => Order::CHANNEL_POS,
            'created_by' => $user->id,
            'customer_id' => $customer?->id,
            'order_number' => CheckoutService::nextNumber($branch->id),
            // Sem acompanhamento pelo link: token aleatório descartado.
            'tracking_token_hash' => hash('sha256', bin2hex(random_bytes(20))),
            'status' => $kitchen ? OrderStatus::Accepted : OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'payment_method' => PaymentMethod::Pos,
            'change_given' => $change !== null ? Money::format($change) : null,
            'fulfillment_type' => FulfillmentType::Counter,
            'customer_name' => $name,
            'customer_phone' => $phone ?? '',
            'notes' => $data['notes'] ?? null,
            'subtotal' => Money::format($subtotal),
            'discount_total' => Money::format($promotion + $discount + $loyaltyDiscount),
            'delivery_fee' => '0.00',
            'total' => Money::format($total),
            'placed_at' => $now,
            'accepted_at' => $now,
            'completed_at' => $kitchen ? null : $now,
        ]);

        if ($cart !== null) {
            foreach ($cart->items as $item) {
                CheckoutService::snapshotItem($order, $item, $view['quotes'][$item->id]);
            }
            $cart->forceFill(['converted_at' => $now, 'order_public_id' => $order->public_id])->save();
        }
        foreach ($custom as $c) {
            OrderItem::query()->create([
                'order_id' => $order->id, 'product_id' => null, 'product_name_snapshot' => $c['name'], 'variant_name_snapshot' => '',
                'unit_price' => Money::format($c['unit']), 'quantity' => $c['qty'], 'line_total' => Money::format($c['unit'] * $c['qty']), 'notes' => $c['notes'],
            ]);
        }

        $percentLabel = fn (float $v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
        $totals = [
            ['subtotal', 'Subtotal', $subtotal],
            ...array_map(fn (array $d) => ['promotion', $d['label'], $d['amount_cents']], $view['discounts']),
            ...($discount > 0 ? [['discount', ($data['discount']['type'] ?? 'amount') === 'percent' ? 'Desconto ('.$percentLabel((float) str_replace(',', '.', (string) $data['discount']['value'])).'%)' : 'Desconto', $discount]] : []),
            ...($loyaltyDiscount > 0 ? [['loyalty', $loyaltyLabel, $loyaltyDiscount]] : []),
            ...($service > 0 ? [['service', 'Taxa de serviço ('.$percentLabel($servicePercent).'%)', $service]] : []),
            ['total', 'Total', $total],
        ];
        foreach ($totals as $position => [$code, $label, $amount]) {
            OrderTotal::query()->create(['order_id' => $order->id, 'code' => $code, 'label' => $label, 'amount' => Money::format($amount), 'sort_order' => $position]);
        }

        foreach ($parts as $p) {
            OrderPayment::query()->create(['order_id' => $order->id, 'method' => $p['method'], 'amount' => Money::format($p['cents'])]);
        }
        if ($loyalty !== null) {
            $this->loyalty->consume($loyalty['card'], $order, $loyaltyDiscount);
        }

        OrderStatusHistory::query()->create(['order_id' => $order->id, 'old_status' => null, 'new_status' => $order->status, 'actor_user_id' => $user->id, 'note' => 'Venda no PDV']);

        $this->book->recordPosSale($order, $user);
        if (! $kitchen) {
            $this->loyalty->stampFor($order);
        }
        $this->audit->record('pos.sale', $order, [
            'total' => $order->total, 'discount' => Money::format($discount), 'service' => Money::format($service),
            'payments' => array_map(fn ($p) => $p['method']->value, $parts), 'kitchen' => $kitchen,
        ]);

        $body = ['data' => [
            'order_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'total' => $order->total,
            'change' => $change !== null ? Money::format($change) : null,
        ]];
        $reservation->forceFill(['status_code' => 201, 'response_json' => $body])->save();

        return ['status' => 201, 'body' => $body];
    }
}

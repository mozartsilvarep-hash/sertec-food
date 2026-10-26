<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Catalog\Pricing\PriceQuote;
use App\Modules\Ordering\Enums\FulfillmentType;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Enums\PaymentMethod;
use App\Modules\Ordering\Enums\PaymentStatus;
use App\Modules\Ordering\Models\Cart;
use App\Modules\Ordering\Models\CartItem;
use App\Modules\Ordering\Models\CouponRedemption;
use App\Modules\Ordering\Models\Customer;
use App\Modules\Ordering\Models\IdempotencyKey;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Models\OrderItem;
use App\Modules\Ordering\Models\OrderItemOption;
use App\Modules\Ordering\Models\OrderSequence;
use App\Modules\Ordering\Models\OrderStatusHistory;
use App\Modules\Ordering\Models\OrderTotal;
use App\Modules\Ordering\Support\Phone;
use App\Modules\Payments\Enums\Provider;
use App\Modules\Payments\Models\IntegrationConnection;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentInitiator;
use App\Modules\Tenancy\Schedule\OpeningSchedule;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Transforma um carrinho em pedido, numa única transação:
 *
 *  1. reserva a Idempotency-Key (UNIQUE) — requisição repetida devolve a
 *     mesma resposta; requisição concorrente espera o commit da primeira;
 *  2. trava o carrinho (SELECT … FOR UPDATE);
 *  3. revalida horário, disponibilidade e preços no servidor;
 *  4. calcula entrega e totais (preço do navegador é ignorado);
 *  5. grava pedido + snapshots + composição do total + histórico;
 *  6. marca o carrinho como convertido;
 *  7. pagamento online: grava o pedido como awaiting_payment + a linha em
 *     `payments`. A cobrança no provedor é criada DEPOIS do commit
 *     (CheckoutController → PaymentInitiator), nunca segurando a transação.
 */
final class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly DeliveryQuote $delivery,
        private readonly TenantContext $context,
        private readonly CouponService $coupons,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validado por CheckoutRequest
     * @return array{status: int, body: array<string, mixed>, payment?: Payment|null}
     */
    public function checkout(string $idempotencyKey, array $data): array
    {
        $hash = hash('sha256', (string) json_encode($data));

        try {
            return DB::transaction(fn () => $this->run($idempotencyKey, $hash, $data));
        } catch (QueryException $e) {
            // Outra requisição com a mesma chave chegou primeiro e já gravou.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return $this->replay($idempotencyKey, $hash);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status: int, body: array<string, mixed>, payment?: Payment|null}
     */
    private function run(string $key, string $hash, array $data): array
    {
        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing !== null) {
            return $this->respondWith($existing, $hash);
        }

        // Reserva a chave antes de tudo: concorrentes bloqueiam neste INSERT.
        $reservation = IdempotencyKey::query()->create([
            'key' => $key,
            'request_hash' => $hash,
            'status_code' => 0,
            'response_json' => [],
        ]);

        $cart = $this->carts->find((string) $data['cart_token']);
        /** @var Cart $cart */
        $cart = Cart::query()->with('branch')->whereKey($cart->id)->lockForUpdate()->firstOrFail();
        if (! $cart->isUsable()) {
            throw OrderingException::make('cart_not_found', 'Carrinho não encontrado ou expirado.', null, 404);
        }

        $branch = $cart->branch;
        $tenant = $this->context->get();
        $now = CarbonImmutable::now();

        $schedule = OpeningSchedule::forBranch($branch, $tenant->timezone);
        if ($schedule->hasAnyHours() && ! $schedule->isOpenAt($now)) {
            throw OrderingException::make('store_closed', 'O estabelecimento está fechado agora.', [
                'next_opening_at' => $schedule->nextOpeningAt($now)?->toIso8601String(),
            ]);
        }

        $view = $this->carts->view($cart);
        if ($view['items'] === []) {
            throw OrderingException::make('cart_empty', 'Seu carrinho está vazio.');
        }
        if (! $view['valid']) {
            throw OrderingException::make('cart_invalid', 'Alguns itens mudaram. Revise o carrinho.', [
                'items' => array_values(array_filter(array_map(
                    fn (array $i) => $i['valid'] ? null : ['id' => $i['id'], 'problem' => $i['problem']],
                    $view['items'],
                ))),
            ]);
        }

        /** @var array{type: string, zone_id?: string|null, address?: array<string, string|null>|null} $fulfillmentData */
        $fulfillmentData = $data['fulfillment'];
        $fulfillment = FulfillmentType::from($fulfillmentData['type']);
        $address = $fulfillment === FulfillmentType::Delivery ? ($fulfillmentData['address'] ?? null) : null;
        $subtotal = $view['subtotal_cents'];
        $deliveryQuote = $this->delivery->quote($branch, $fulfillment, $fulfillmentData['zone_id'] ?? null, $address, $subtotal);
        /** @var array{name: string, phone: string, email?: string|null} $customerData */
        $customerData = $data['customer'];
        $phone = (string) Phone::normalize($customerData['phone']);

        // Cupom: travado (FOR UPDATE) para o limite de usos valer com pedidos simultâneos.
        $coupon = null;
        $discount = 0;
        $couponLabel = null;
        if (! empty($data['coupon'])) {
            $coupon = $this->coupons->find((string) $data['coupon'], lock: true);
            ['discount_cents' => $discount, 'label' => $couponLabel] = $this->coupons->evaluate($coupon, $subtotal, $deliveryQuote['fee_cents'], $phone);
        }

        $total = $subtotal + $deliveryQuote['fee_cents'] - $discount;

        /** @var array{method: string, change_for?: string|null} $paymentData */
        $paymentData = $data['payment'];
        $method = PaymentMethod::from($paymentData['method']);
        if ($method->isOnline() && $total <= 0) {
            throw OrderingException::make('payment_method_unavailable', 'Pedido sem valor a pagar: escolha pagar na entrega.');
        }
        if ($method->isOnline() && IntegrationConnection::activeFor(Provider::MercadoPago) === null) {
            throw OrderingException::make('payment_method_unavailable', 'Pagamento online indisponível. Escolha pagar na entrega.');
        }
        $changeFor = $method === PaymentMethod::Cash && ! empty($paymentData['change_for']) ? Money::toCents((string) $paymentData['change_for']) : null;
        if ($changeFor !== null && $changeFor < $total) {
            throw OrderingException::make('change_for_too_low', 'O valor para troco precisa ser maior que o total do pedido.');
        }

        $customer = Customer::query()->firstOrNew(['phone' => $phone]);
        $customer->fill(['name' => $customerData['name'], 'email' => $customerData['email'] ?? $customer->email, 'last_order_at' => $now]);
        $customer->save();

        $number = $this->nextNumber($branch->id);
        $trackingToken = bin2hex(random_bytes(20));

        $order = Order::query()->create([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'order_number' => $number,
            'tracking_token_hash' => hash('sha256', $trackingToken),
            // Online: o restaurante só vê o pedido depois do pagamento confirmado.
            'status' => $method->isOnline() ? OrderStatus::AwaitingPayment : OrderStatus::PendingAcceptance,
            'payment_status' => PaymentStatus::Pending,
            'payment_method' => $method,
            'change_for' => $changeFor !== null ? Money::format($changeFor) : null,
            'fulfillment_type' => $fulfillment,
            'customer_name' => $customerData['name'],
            'customer_phone' => $phone,
            'address_snapshot_json' => $address === null ? null : array_intersect_key($address, array_flip(['street', 'number', 'complement', 'neighborhood', 'reference'])),
            'delivery_zone_name' => $deliveryQuote['zone']?->name,
            'notes' => $data['notes'] ?? null,
            'subtotal' => Money::format($subtotal),
            'discount_total' => Money::format($discount),
            'delivery_fee' => Money::format($deliveryQuote['fee_cents']),
            'total' => Money::format($total),
            'eta_minutes' => $deliveryQuote['eta_minutes'],
            'placed_at' => $now,
        ]);

        foreach ($cart->items as $item) {
            $this->snapshotItem($order, $item, $view['quotes'][$item->id]);
        }

        $lines = [
            ['subtotal', 'Subtotal', $subtotal],
            ...($fulfillment === FulfillmentType::Delivery ? [['delivery_fee', 'Entrega'.($deliveryQuote['zone'] ? ' ('.$deliveryQuote['zone']->name.')' : ''), $deliveryQuote['fee_cents']]] : []),
            ...($coupon !== null && $discount > 0 ? [['discount', (string) $couponLabel, $discount]] : []),
            ['total', 'Total', $total],
        ];
        foreach ($lines as $position => [$code, $label, $amount]) {
            OrderTotal::query()->create(['order_id' => $order->id, 'code' => $code, 'label' => $label, 'amount' => Money::format($amount), 'sort_order' => $position]);
        }

        if ($coupon !== null) {
            CouponRedemption::query()->create([
                'coupon_id' => $coupon->id, 'order_id' => $order->id, 'customer_phone' => $phone,
                'amount' => Money::format($discount), 'status' => CouponRedemption::APPLIED,
            ]);
            $coupon->increment('times_used');
        }

        OrderStatusHistory::query()->create(['order_id' => $order->id, 'old_status' => null, 'new_status' => $order->status]);

        $payment = $method->isOnline() ? PaymentInitiator::open($order) : null;

        $cart->forceFill(['converted_at' => $now, 'order_public_id' => $order->public_id])->save();

        $body = ['data' => [
            'order_id' => $order->public_id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'payment_method' => $order->payment_method->value,
            'fulfillment_type' => $order->fulfillment_type->value,
            'total' => $order->total,
            'discount_total' => $order->discount_total,
            'eta_minutes' => $order->eta_minutes,
            'payment_required' => $payment !== null,
            'payment_expires_at' => $payment?->expires_at->toIso8601String(),
            // Mostrado uma vez; o banco guarda só o hash.
            'tracking_token' => $trackingToken,
        ]];

        $reservation->forceFill(['status_code' => 201, 'response_json' => $body])->save();

        return ['status' => 201, 'body' => $body, 'payment' => $payment];
    }

    private function snapshotItem(Order $order, CartItem $item, PriceQuote $quote): void
    {
        $orderItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $item->product_id,
            'product_name_snapshot' => $quote->productName,
            'variant_name_snapshot' => $quote->variantName,
            'unit_price' => Money::format($quote->unitPriceCents),
            'quantity' => $item->quantity,
            'line_total' => Money::format($quote->unitPriceCents * $item->quantity),
            'notes' => $item->notes,
        ]);

        foreach ($quote->lines as $line) {
            OrderItemOption::query()->create([
                'order_item_id' => $orderItem->id,
                'group_name_snapshot' => $line['group_name'],
                'option_name_snapshot' => $line['option_name'],
                'quantity' => $line['quantity'],
                'charged_quantity' => $line['charged_quantity'],
                'unit_price' => Money::format($line['unit_price_cents']),
                'total' => Money::format($line['total_cents']),
            ]);
        }
    }

    private function nextNumber(int $branchId): int
    {
        OrderSequence::query()->firstOrCreate(['branch_id' => $branchId], ['last_number' => 0]);

        /** @var OrderSequence $sequence */
        $sequence = OrderSequence::query()->where('branch_id', $branchId)->lockForUpdate()->firstOrFail();
        $sequence->last_number++;
        $sequence->save();

        return $sequence->last_number;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function replay(string $key, string $hash): array
    {
        $existing = IdempotencyKey::query()->where('key', $key)->first();
        if ($existing === null) {
            throw OrderingException::make('idempotency_in_progress', 'Pedido em processamento. Tente novamente em instantes.', null, 409);
        }

        return $this->respondWith($existing, $hash);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function respondWith(IdempotencyKey $existing, string $hash): array
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw OrderingException::make('idempotency_conflict', 'Esta chave de requisição já foi usada com outros dados.', null, 409);
        }
        if ($existing->status_code === 0) {
            throw OrderingException::make('idempotency_in_progress', 'Pedido em processamento. Tente novamente em instantes.', null, 409);
        }

        return ['status' => $existing->status_code, 'body' => $existing->response_json];
    }
}

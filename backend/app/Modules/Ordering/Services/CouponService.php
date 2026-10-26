<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Coupon;
use App\Modules\Ordering\Models\CouponRedemption;
use App\Modules\Ordering\Models\Order;
use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Features\FeatureGate;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Regras de cupom, as mesmas na prévia do carrinho e no checkout. No
 * checkout o cupom é lido com FOR UPDATE: dois pedidos simultâneos não
 * passam do limite de usos.
 */
final class CouponService
{
    public function __construct(private readonly FeatureGate $features) {}

    public function find(string $code, bool $lock = false): Coupon
    {
        if (! $this->features->enabled(Feature::COUPONS)) {
            throw OrderingException::make('coupon_invalid', 'Cupom inválido ou expirado.');
        }

        $coupon = Coupon::query()->where('code', Coupon::normalize($code))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();

        if ($coupon === null || ! $coupon->active) {
            throw OrderingException::make('coupon_invalid', 'Cupom inválido ou expirado.');
        }

        return $coupon;
    }

    /**
     * @return array{discount_cents: int, label: string}
     */
    public function evaluate(Coupon $coupon, int $subtotalCents, int $deliveryFeeCents, ?string $phone): array
    {
        $now = Carbon::now();
        if (($coupon->starts_at !== null && $coupon->starts_at->gt($now)) || ($coupon->ends_at !== null && $coupon->ends_at->lte($now))) {
            throw OrderingException::make('coupon_invalid', 'Cupom inválido ou expirado.');
        }
        if ($coupon->usage_limit !== null && $coupon->times_used >= $coupon->usage_limit) {
            throw OrderingException::make('coupon_exhausted', 'Este cupom já atingiu o limite de usos.');
        }
        if ($coupon->min_subtotal !== null && $subtotalCents < Money::toCents($coupon->min_subtotal)) {
            throw OrderingException::make('coupon_min_subtotal', 'Este cupom vale para pedidos a partir de R$ '.str_replace('.', ',', $coupon->min_subtotal).'.', [
                'min_subtotal' => $coupon->min_subtotal,
            ]);
        }

        if ($phone !== null) {
            if ($coupon->per_customer_limit !== null) {
                $used = CouponRedemption::query()->where('coupon_id', $coupon->id)->where('customer_phone', $phone)
                    ->where('status', CouponRedemption::APPLIED)->count();
                if ($used >= $coupon->per_customer_limit) {
                    throw OrderingException::make('coupon_customer_limit', 'Você já usou este cupom.');
                }
            }
            if ($coupon->first_order_only && Order::query()->where('customer_phone', $phone)
                ->whereNotIn('status', [OrderStatus::Rejected, OrderStatus::Cancelled, OrderStatus::AwaitingPayment])->exists()) {
                throw OrderingException::make('coupon_first_order_only', 'Este cupom é só para o primeiro pedido.');
            }
        }

        $discount = match ($coupon->discount_type) {
            Coupon::PERCENT => $subtotalCents - intdiv($subtotalCents * (10000 - (int) $coupon->percent_bp) + 5000, 10000),
            Coupon::FIXED => Money::toCents((string) $coupon->amount),
            Coupon::FREE_DELIVERY => $deliveryFeeCents,
            default => 0,
        };
        if ($coupon->discount_type === Coupon::PERCENT && $coupon->max_discount !== null) {
            $discount = min($discount, Money::toCents($coupon->max_discount));
        }
        // Nunca mais que o subtotal (+ entrega, no frete grátis).
        $discount = max(0, min($discount, $coupon->discount_type === Coupon::FREE_DELIVERY ? $deliveryFeeCents : $subtotalCents));

        return ['discount_cents' => $discount, 'label' => self::label($coupon)];
    }

    public static function label(Coupon $coupon): string
    {
        return 'Cupom '.$coupon->code.($coupon->discount_type === Coupon::FREE_DELIVERY ? ' (entrega grátis)' : '');
    }

    /** Pedido recusado/cancelado: o uso volta (o cliente pode usar de novo). */
    public static function voidFor(Order $order): void
    {
        $redemption = CouponRedemption::query()->where('order_id', $order->id)->where('status', CouponRedemption::APPLIED)->lockForUpdate()->first();
        if ($redemption === null) {
            return;
        }
        $redemption->forceFill(['status' => CouponRedemption::VOIDED])->save();
        Coupon::query()->whereKey($redemption->coupon_id)->where('times_used', '>', 0)->decrement('times_used');
    }
}

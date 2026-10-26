<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Ordering\Models\Coupon;
use App\Modules\Ordering\Models\CouponRedemption;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cupons do estabelecimento (marketing.manage). Código único por tenant,
 * sempre em maiúsculas. Cupom já usado não é apagado: é desativado.
 */
class CouponController
{
    public function index(TenantContext $context): JsonResponse
    {
        $tz = $context->get()->timezone;
        $totals = CouponRedemption::query()->where('status', CouponRedemption::APPLIED)
            ->selectRaw('coupon_id, SUM(amount) AS s')->groupBy('coupon_id')->toBase()->pluck('s', 'coupon_id');

        return new JsonResponse(['data' => Coupon::query()->orderByDesc('id')->get()
            ->map(fn (Coupon $c) => self::present($c, $tz, (string) ($totals[$c->id] ?? '0')))->values()]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $coupon = new Coupon;
        $this->fill($coupon, $request, $context->get()->timezone, creating: true);
        $coupon->save();

        return new JsonResponse(['data' => self::present($coupon, $context->get()->timezone, '0')], 201);
    }

    public function update(Request $request, Coupon $coupon, TenantContext $context): JsonResponse
    {
        $this->fill($coupon, $request, $context->get()->timezone, creating: false);
        $coupon->save();

        return new JsonResponse(['data' => self::present($coupon, $context->get()->timezone, '0')]);
    }

    public function destroy(Coupon $coupon): JsonResponse
    {
        if ($coupon->times_used > 0 || CouponRedemption::query()->where('coupon_id', $coupon->id)->exists()) {
            throw new ApiException(409, 'coupon_used', 'Cupom já usado em pedidos: desative em vez de apagar.');
        }
        $coupon->delete();

        return new JsonResponse(null, 204);
    }

    private function fill(Coupon $coupon, Request $request, string $tz, bool $creating): void
    {
        $req = $creating ? 'required' : 'sometimes';
        $money = ['nullable', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'];
        $data = $request->validate([
            'code' => [$req, 'string', 'min:3', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'description' => ['nullable', 'string', 'max:120'],
            'discount_type' => [$req, Rule::in([Coupon::PERCENT, Coupon::FIXED, Coupon::FREE_DELIVERY])],
            'percent' => ['nullable', 'string', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'amount' => $money,
            'max_discount' => $money,
            'min_subtotal' => $money,
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'first_order_only' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use só letras, números, hífen e sublinhado.'], ['code' => 'código', 'percent' => 'desconto', 'amount' => 'valor']);

        if (isset($data['code'])) {
            $code = Coupon::normalize($data['code']);
            $taken = Coupon::query()->where('code', $code)->when($coupon->exists, fn ($q) => $q->whereKeyNot($coupon->id))->exists();
            if ($taken) {
                throw ValidationException::withMessages(['code' => 'Já existe um cupom com este código.']);
            }
            $coupon->code = $code;
        }
        foreach (['description', 'discount_type', 'usage_limit', 'per_customer_limit'] as $f) {
            if (array_key_exists($f, $data)) {
                $coupon->{$f} = $data[$f];
            }
        }
        foreach (['amount', 'max_discount', 'min_subtotal'] as $f) {
            if (array_key_exists($f, $data)) {
                $coupon->{$f} = $data[$f] === null || $data[$f] === '' ? null : Money::format(Money::toCents(str_replace(',', '.', $data[$f])));
            }
        }
        if (array_key_exists('percent', $data)) {
            $coupon->percent_bp = $data['percent'] === null ? null : (int) round((float) str_replace(',', '.', $data['percent']) * 100);
        }
        foreach (['starts_at', 'ends_at'] as $f) {
            if (array_key_exists($f, $data)) {
                $coupon->{$f} = $data[$f] === null ? null : Carbon::parse($data[$f], $tz)->utc();
            }
        }
        foreach (['first_order_only', 'active'] as $f) {
            if (array_key_exists($f, $data)) {
                $coupon->{$f} = (bool) $data[$f];
            }
        }
        $coupon->active ??= true;
        $coupon->first_order_only ??= false;

        // Coerência por tipo
        if ($coupon->discount_type === Coupon::PERCENT && ($coupon->percent_bp === null || $coupon->percent_bp < 1 || $coupon->percent_bp > 10000)) {
            throw ValidationException::withMessages(['percent' => 'Informe o desconto em % (até 100).']);
        }
        if ($coupon->discount_type === Coupon::FIXED && ($coupon->amount === null || Money::toCents($coupon->amount) <= 0)) {
            throw ValidationException::withMessages(['amount' => 'Informe o valor do desconto.']);
        }
        if ($coupon->starts_at !== null && $coupon->ends_at !== null && $coupon->ends_at->lte($coupon->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => 'O fim precisa ser depois do início.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Coupon $c, string $tz, string $discountGiven): array
    {
        return [
            'id' => $c->public_id,
            'code' => $c->code,
            'description' => $c->description,
            'discount_type' => $c->discount_type,
            'percent' => $c->percent_bp === null ? null : rtrim(rtrim(number_format($c->percent_bp / 100, 2, '.', ''), '0'), '.'),
            'amount' => $c->amount,
            'max_discount' => $c->max_discount,
            'min_subtotal' => $c->min_subtotal,
            'starts_at' => $c->starts_at?->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends_at' => $c->ends_at?->setTimezone($tz)->format('Y-m-d\TH:i'),
            'usage_limit' => $c->usage_limit,
            'per_customer_limit' => $c->per_customer_limit,
            'first_order_only' => $c->first_order_only,
            'times_used' => $c->times_used,
            'discount_given' => Money::format(Money::toCents(number_format((float) $discountGiven, 2, '.', ''))),
            'active' => $c->active,
        ];
    }
}

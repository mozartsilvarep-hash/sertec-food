<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Modules\Catalog\Http\Controllers\PublicCatalogController;
use App\Modules\Ordering\Models\Cart;
use App\Modules\Ordering\Models\CartItem;
use App\Modules\Ordering\Models\Coupon;
use App\Modules\Ordering\Services\CartService;
use App\Modules\Ordering\Services\CouponService;
use App\Modules\Ordering\Support\Phone;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carrinho anônimo. O token é a "chave" do carrinho: quem o tem, acessa.
 */
class CartController
{
    public function store(Request $request, CartService $carts): JsonResponse
    {
        $branch = PublicCatalogController::branch($request->merge(['branch' => $request->input('branch_id')]));
        [$cart, $token] = $carts->create($branch);

        return new JsonResponse(['data' => ['token' => $token, ...$this->present($carts, $cart)]], 201);
    }

    /**
     * POST /carts/{token}/coupon {code, phone?} — prévia do desconto. As
     * regras por cliente (limite, primeiro pedido) só são checadas com o
     * celular; o checkout valida tudo de novo.
     */
    public function coupon(Request $request, string $token, CartService $carts, CouponService $coupons): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:30'], 'phone' => ['nullable', 'string', 'max:25']]);
        $cart = $carts->find($token);
        $view = $carts->view($cart);
        $coupon = $coupons->find($data['code']);
        $phone = isset($data['phone']) ? Phone::normalize($data['phone']) : null;
        $result = $coupons->evaluate($coupon, $view['subtotal_cents'] - $view['discount_cents'], 0, $phone);

        return new JsonResponse(['data' => [
            'code' => $coupon->code,
            'label' => $result['label'],
            'description' => $coupon->description,
            'discount_type' => $coupon->discount_type,
            // Frete grátis: o valor depende do bairro (calculado no checkout).
            'discount' => Money::format($result['discount_cents']),
            'free_delivery' => $coupon->discount_type === Coupon::FREE_DELIVERY,
        ]]);
    }

    public function show(string $token, CartService $carts): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($carts, $carts->find($token))]);
    }

    public function addItem(Request $request, string $token, CartService $carts): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'size:26'],
            'variant_id' => ['required', 'string', 'size:26'],
            'options' => ['present', 'array', 'max:60'],
            'options.*.option' => ['required', 'string', 'size:26'],
            'options.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartItem::MAX_QUANTITY],
            'notes' => ['nullable', 'string', 'max:140'],
        ]);

        $cart = $carts->find($token);
        /** @var list<array<string, mixed>> $options */
        $options = $data['options'];
        $carts->add($cart, $data['product_id'], $data['variant_id'], $options, (int) $data['quantity'], $data['notes'] ?? null);

        return new JsonResponse(['data' => $this->present($carts, $cart->refresh())], 201);
    }

    public function updateItem(Request $request, string $token, string $item, CartService $carts): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:'.CartItem::MAX_QUANTITY],
            'notes' => ['sometimes', 'nullable', 'string', 'max:140'],
        ]);

        $cart = $carts->find($token);
        $carts->update($cart, $item, isset($data['quantity']) ? (int) $data['quantity'] : null, $data['notes'] ?? null, array_key_exists('notes', $data));

        return new JsonResponse(['data' => $this->present($carts, $cart->refresh())]);
    }

    public function removeItem(string $token, string $item, CartService $carts): JsonResponse
    {
        $cart = $carts->find($token);
        $carts->remove($cart, $item);

        return new JsonResponse(['data' => $this->present($carts, $cart->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(CartService $carts, Cart $cart): array
    {
        $view = $carts->view($cart);

        return [
            'branch_id' => $cart->branch->public_id,
            'expires_at' => $cart->expires_at->toIso8601String(),
            'items' => $view['items'],
            'subtotal' => Money::format($view['subtotal_cents']),
            // Promoções "leve N, pague M" (já calculadas; o checkout recalcula).
            'discounts' => array_map(fn (array $d) => ['label' => $d['label'], 'amount' => Money::format($d['amount_cents'])], $view['discounts']),
            'discount_total' => Money::format($view['discount_cents']),
            'net_subtotal' => Money::format($view['subtotal_cents'] - $view['discount_cents']),
            'valid' => $view['valid'],
            'count' => array_sum(array_column($view['items'], 'quantity')),
        ];
    }
}

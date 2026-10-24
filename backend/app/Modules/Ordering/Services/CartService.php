<?php

namespace App\Modules\Ordering\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Pricing\PriceCalculator;
use App\Modules\Catalog\Pricing\PriceQuote;
use App\Modules\Catalog\Pricing\PricingException;
use App\Modules\Ordering\Models\Cart;
use App\Modules\Ordering\Models\CartItem;
use App\Modules\Tenancy\Models\Branch;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Carrinho anônimo no servidor. Guarda apenas O QUE o cliente escolheu;
 * o preço é recalculado a cada leitura pelo PriceCalculator.
 */
final class CartService
{
    public function __construct(private readonly PriceCalculator $calculator) {}

    /**
     * @return array{0: Cart, 1: string} carrinho e token em claro (devolvido uma vez)
     */
    public function create(Branch $branch): array
    {
        $token = bin2hex(random_bytes(24));

        $cart = Cart::query()->create([
            'branch_id' => $branch->id,
            'token_hash' => Cart::hashToken($token),
            'expires_at' => Carbon::now()->addHours(Cart::TTL_HOURS),
        ]);

        return [$cart, $token];
    }

    public function find(string $token): Cart
    {
        $cart = strlen($token) === 48 && ctype_xdigit($token)
            ? Cart::query()->with('branch')->where('token_hash', Cart::hashToken($token))->first()
            : null;

        if ($cart === null || ! $cart->isUsable()) {
            throw OrderingException::make('cart_not_found', 'Carrinho não encontrado ou expirado.', null, 404);
        }

        return $cart;
    }

    /**
     * @param  list<array<string, mixed>>  $selections
     */
    public function add(Cart $cart, string $productId, string $variantId, array $selections, int $quantity, ?string $notes): CartItem
    {
        $product = Product::query()->where('public_id', $productId)->where('active', true)->first();
        if ($product === null) {
            throw new PricingException('product_unavailable', 'Este produto não está disponível.');
        }

        // Valida já na entrada: o cliente recebe o erro na hora de adicionar.
        $this->calculator->quote($product, $variantId, $selections, $cart->branch);

        $variant = $product->variants->firstWhere('public_id', $variantId);

        $item = CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
            'selections_json' => array_map(fn (array $s) => [
                'option' => (string) ($s['option'] ?? ''),
                'quantity' => (int) ($s['quantity'] ?? 1),
            ], $selections),
            'notes' => $notes,
        ]);

        $this->touch($cart);

        return $item;
    }

    public function update(Cart $cart, string $itemId, ?int $quantity, ?string $notes, bool $notesGiven): void
    {
        $item = $this->item($cart, $itemId);

        if ($quantity === 0) {
            $item->delete();
        } else {
            $item->fill(array_filter([
                'quantity' => $quantity,
            ], fn ($v) => $v !== null));
            if ($notesGiven) {
                $item->notes = $notes;
            }
            $item->save();
        }

        $this->touch($cart);
    }

    public function remove(Cart $cart, string $itemId): void
    {
        $this->item($cart, $itemId)->delete();
        $this->touch($cart);
    }

    /**
     * Leitura com preços atuais. Itens que deixaram de ser válidos aparecem
     * com valid=false e o motivo — nunca somem sem aviso.
     *
     * @return array{items: list<array<string, mixed>>, subtotal_cents: int, valid: bool, quotes: array<int, PriceQuote>}
     */
    public function view(Cart $cart): array
    {
        $cart->loadMissing('items.product.variants', 'items.variant', 'branch');

        $items = [];
        $quotes = [];
        $subtotal = 0;
        $valid = true;

        foreach ($cart->items as $item) {
            $product = $item->product;
            $variant = $item->variant;
            $row = [
                'id' => $item->public_id,
                'product_id' => $product?->public_id,
                'product_name' => $product->name ?? 'Produto indisponível',
                'variant_name' => $variant?->name,
                'quantity' => $item->quantity,
                'notes' => $item->notes,
                'selections' => $item->selections_json,
                'options' => [],
                'unit_price' => null,
                'line_total' => null,
                'valid' => false,
                'problem' => null,
            ];

            try {
                if ($product === null || $variant === null) {
                    throw new PricingException('product_unavailable', 'Este produto não está mais disponível.');
                }
                /** @var list<array<string, mixed>> $selections */
                $selections = $item->selections_json;
                $quote = $this->calculator->quote($product, $variant->public_id, $selections, $cart->branch);
                $line = $quote->unitPriceCents * $item->quantity;
                $subtotal += $line;
                $quotes[$item->id] = $quote;

                $row['options'] = array_map(fn (array $l) => [
                    'group_name' => $l['group_name'],
                    'option_name' => $l['option_name'],
                    'quantity' => $l['quantity'],
                ], $quote->lines);
                $row['unit_price'] = Money::format($quote->unitPriceCents);
                $row['line_total'] = Money::format($line);
                $row['valid'] = true;
            } catch (PricingException $e) {
                $valid = false;
                $row['problem'] = ['reason' => $e->reason, 'message' => $e->getMessage()];
            }

            $items[] = $row;
        }

        return ['items' => $items, 'subtotal_cents' => $subtotal, 'valid' => $valid && $items !== [], 'quotes' => $quotes];
    }

    private function item(Cart $cart, string $itemId): CartItem
    {
        $item = CartItem::query()->where('cart_id', $cart->id)->where('public_id', $itemId)->first();

        return $item ?? throw OrderingException::make('cart_item_not_found', 'Item não encontrado no carrinho.', null, 404);
    }

    private function touch(Cart $cart): void
    {
        $cart->forceFill(['expires_at' => Carbon::now()->addHours(Cart::TTL_HOURS)])->save();
    }
}

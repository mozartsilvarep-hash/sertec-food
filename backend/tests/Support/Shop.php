<?php

namespace Tests\Support;

use App\Modules\Ordering\Models\DeliveryZone;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Atalhos para testes de carrinho/checkout via HTTP (como o navegador faria).
 */
final class Shop
{
    public static function enableDelivery(CatalogFixture $f): DeliveryZone
    {
        return inTenant($f->tenant, function () use ($f) {
            $f->branch->update(['delivery_enabled' => true, 'pickup_enabled' => true]);

            return DeliveryZone::query()->create([
                'branch_id' => $f->branch->id,
                'name' => 'Orla',
                'neighborhoods_json' => ['Pajuçara', 'Ponta Verde'],
                'fee' => '7.00',
                'minimum_order' => '30.00',
                'free_above' => '120.00',
                'eta_minutes' => 50,
            ]);
        });
    }

    public static function newCart(Tenant $tenant): string
    {
        return (string) test()->postJson(tenantUrl($tenant, 'carts'))->assertCreated()->json('data.token');
    }

    public static function addPizza(CatalogFixture $f, string $token, int $qty = 1, array $flavors = ['Calabresa' => 1], array $crusts = []): TestResponse
    {
        return test()->postJson(tenantUrl($f->tenant, "carts/{$token}/items"), [
            'product_id' => $f->pizza->public_id,
            'variant_id' => $f->medium->public_id,
            'options' => $f->pick($flavors, $crusts),
            'quantity' => $qty,
        ]);
    }

    public static function addSoda(CatalogFixture $f, string $token, int $qty = 1): TestResponse
    {
        return test()->postJson(tenantUrl($f->tenant, "carts/{$token}/items"), [
            'product_id' => $f->soda->public_id,
            'variant_id' => $f->sodaVariant->public_id,
            'options' => [],
            'quantity' => $qty,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function pickupPayload(string $token, array $overrides = []): array
    {
        return array_replace_recursive([
            'cart_token' => $token,
            'customer' => ['name' => 'Maria Souza', 'phone' => '(82) 99876-5432'],
            'fulfillment' => ['type' => 'pickup'],
            'payment' => ['method' => 'cash'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function deliveryPayload(string $token, DeliveryZone $zone, array $overrides = []): array
    {
        return array_replace_recursive(self::pickupPayload($token), [
            'fulfillment' => [
                'type' => 'delivery',
                'zone_id' => $zone->public_id,
                'address' => ['street' => 'Av. Álvaro Otacílio', 'number' => '1000', 'neighborhood' => 'Pajucara', 'complement' => 'Apto 302'],
            ],
            'payment' => ['method' => 'card_on_delivery'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function checkout(Tenant $tenant, array $payload, ?string $key = null): TestResponse
    {
        return test()->withHeader('Idempotency-Key', $key ?? Str::uuid()->toString())
            ->postJson(tenantUrl($tenant, 'checkout'), $payload);
    }
}

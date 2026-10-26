<?php

use App\Modules\Loyalty\Models\LoyaltyCard;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Privacy\CustomerPrivacy;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\CatalogFixture;
use Tests\Support\Shop;

function withLoyaltyPlan($tenant): void
{
    if (! Plan::query()->where('slug', 'cardapio')->exists()) {
        (new PlanSeeder)->run();
    }
    inTenant($tenant, fn () => Subscription::query()->create(['plan_id' => Plan::query()->where('slug', 'cardapio')->value('id'), 'status' => Subscription::STATUS_ACTIVE]));
}

beforeEach(function () {
    RateLimiter::clear('phone-lookup');
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    withLoyaltyPlan($this->a);
    withLoyaltyPlan($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
    $this->phone = '(79) 99876-5432';
});

function program(object $t, array $body = [])
{
    return withSession($t->session)->putJson(tenantUrl($t->a, 'merchant/loyalty'), $body + [
        'active' => true, 'stamps_required' => 3, 'min_order' => '20,00', 'reward_type' => 'fixed', 'reward_amount' => '10,00',
    ]);
}

/** Pedido de retirada do celular de teste; $pizzas = 0 → só um refrigerante (6,50). */
function loyaltyOrder(object $t, int $pizzas = 1, array $extra = [], ?string $phone = null)
{
    $token = Shop::newCart($t->a);
    $pizzas > 0 ? Shop::addPizza($t->fa, $token, $pizzas, ['Calabresa' => 1]) : Shop::addSoda($t->fa, $token);

    return Shop::checkout($t->a, Shop::pickupPayload($token, ['customer' => ['phone' => $phone ?? $t->phone]] + $extra));
}

function complete(object $t, string $order): void
{
    foreach (['accepted', 'preparing', 'ready', 'completed'] as $s) {
        withSession($t->session)->patchJson(tenantUrl($t->a, "merchant/orders/{$order}/status"), ['status' => $s])->assertOk();
    }
}

function card(object $t): ?LoyaltyCard
{
    return inTenant($t->a, fn () => LoyaltyCard::query()->where('phone', '79998765432')->first());
}

it('configuração do cartão aparece no cardápio; validação em português', function () {
    $this->getJson(tenantUrl($this->a, 'storefront'))->assertOk()->assertJsonPath('data.loyalty', null);
    program($this, ['stamps_required' => 1])->assertStatus(422)->assertJsonPath('details.fields.stamps_required.0', 'O campo selos para o prêmio precisa estar entre 2 e 50.');
    program($this, ['reward_amount' => null])->assertStatus(422)->assertJsonPath('details.fields.reward_amount.0', 'Preencha o campo valor do prêmio.');
    program($this)->assertOk()->assertJsonPath('data.reward_label', 'R$ 10,00 de desconto');

    $this->getJson(tenantUrl($this->a, 'storefront'))->assertJsonPath('data.loyalty', [
        'stamps_required' => 3, 'reward_label' => 'R$ 10,00 de desconto', 'min_order' => '20.00',
        'reward_type' => 'fixed', 'reward_amount' => '10.00', 'reward_percent_bp' => null, 'reward_max' => null,
    ]);
    $this->getJson(tenantUrl($this->b, 'storefront'))->assertJsonPath('data.loyalty', null);
});

it('selo só em pedido concluído acima do mínimo; 3 selos viram 1 prêmio', function () {
    program($this)->assertOk();
    $small = loyaltyOrder($this, 0)->json('data.order_id');
    complete($this, $small);
    expect(card($this))->toBeNull();

    $pending = loyaltyOrder($this)->json('data.order_id'); // não concluído: sem selo
    foreach (range(1, 2) as $i) {
        complete($this, loyaltyOrder($this)->json('data.order_id'));
    }
    expect(card($this)->only(['stamps', 'rewards']))->toBe(['stamps' => 2, 'rewards' => 0]);
    $this->postJson(tenantUrl($this->a, 'customers/lookup'), ['phone' => $this->phone])->assertOk()
        ->assertJsonPath('data.loyalty', ['stamps' => 2, 'required' => 3, 'rewards' => 0, 'reward_label' => 'R$ 10,00 de desconto']);

    complete($this, $pending);
    expect(card($this)->only(['stamps', 'rewards']))->toBe(['stamps' => 0, 'rewards' => 1]);
    // Acompanhamento mostra o cartão do celular do pedido.
    expect(inTenant($this->a, fn () => Order::query()->count()))->toBe(4);
});

it('prêmio no checkout: desconta, aparece nos totais, não usa duas vezes e volta se o pedido for cancelado', function () {
    program($this)->assertOk();
    foreach (range(1, 3) as $i) {
        complete($this, loyaltyOrder($this)->json('data.order_id'));
    }

    $order = loyaltyOrder($this, 1, ['use_loyalty' => true])->assertCreated()
        ->assertJsonPath('data.total', '30.00')->assertJsonPath('data.discount_total', '10.00')->json('data.order_id');
    $model = inTenant($this->a, fn () => Order::query()->where('public_id', $order)->with('totals')->sole());
    expect($model->totals->pluck('amount', 'code')->all())->toMatchArray(['loyalty' => '10.00', 'total' => '30.00'])
        ->and(card($this)->rewards)->toBe(0);
    loyaltyOrder($this, 1, ['use_loyalty' => true])->assertStatus(422)->assertJsonPath('code', 'loyalty_no_reward');

    withSession($this->session)->patchJson(tenantUrl($this->a, "merchant/orders/{$order}/status"), ['status' => 'rejected', 'reason' => 'out_of_stock'])->assertOk();
    expect(card($this)->rewards)->toBe(1);
});

it('prêmio percentual com teto e nunca maior que os itens', function () {
    program($this, ['reward_type' => 'percent', 'reward_percent' => '50', 'reward_max' => '15,00', 'reward_amount' => null, 'stamps_required' => 2])
        ->assertOk()->assertJsonPath('data.reward_label', '50% de desconto (até R$ 15,00)');
    foreach (range(1, 2) as $i) {
        complete($this, loyaltyOrder($this)->json('data.order_id'));
    }
    loyaltyOrder($this, 1, ['use_loyalty' => true])->assertCreated()->assertJsonPath('data.total', '25.00');
});

it('ajuste manual (balcão), histórico no painel, isolamento, LGPD e plano sem fidelidade', function () {
    program($this)->assertOk();
    $adjust = fn (int $n) => withSession($this->session)->postJson(tenantUrl($this->a, 'merchant/loyalty/card/adjust'), ['phone' => $this->phone, 'stamps' => $n, 'note' => 'Compra no balcão']);
    $adjust(4)->assertOk()->assertJsonPath('data', ['stamps' => 1, 'rewards' => 1]);
    $adjust(-5)->assertStatus(422)->assertJsonPath('code', 'loyalty_adjust_invalid');
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/loyalty/card?phone=79998765432'))->assertOk()
        ->assertJsonPath('data.stamps', 1)->assertJsonPath('data.history.0.note', 'Compra no balcão');

    // Mesmo celular no restaurante B: cartão separado.
    expect(inTenant($this->b, fn () => LoyaltyCard::query()->count()))->toBe(0);

    // Titular pede exclusão: cartão some.
    inTenant($this->a, fn () => app(CustomerPrivacy::class)->anonymizeCustomer('79998765432'));
    expect(card($this))->toBeNull();

    $c = makeTenant('tenant-c');
    $owner = makeMember($c);
    withSession(merchantLogin($c, $owner))->getJson(tenantUrl($c, 'merchant/loyalty'))->assertForbidden();
});

<?php

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\BrandColor;
use Tests\Support\CatalogFixture;

beforeEach(function () {
    $this->a = makeTenant('tenant-a');
    $this->b = makeTenant('tenant-b');
    $this->fa = CatalogFixture::create($this->a);
    $this->fb = CatalogFixture::create($this->b);
    $this->session = merchantLogin($this->a, makeMember($this->a));
});

it('valida contraste AA da cor da marca com texto branco', function () {
    expect(BrandColor::isAccessible('#c2410c'))->toBeTrue()
        ->and(BrandColor::isAccessible('#fbbf24'))->toBeFalse();

    withSession($this->session)->patchJson(tenantUrl($this->a, 'merchant/settings'), ['brand_color' => '#FBBF24'])
        ->assertStatus(422)->assertJsonStructure(['details' => ['fields' => ['brand_color']]]);

    withSession($this->session)->patchJson(tenantUrl($this->a, 'merchant/settings'), ['brand_color' => '#9A3412', 'description' => 'Forno a lenha'])
        ->assertOk()->assertJsonPath('data.brand_color', '#9a3412');

    expect($this->b->fresh()->brand_color)->toBeNull();
});

it('não deixa o restaurante trocar nome ou slug pelas configurações', function () {
    withSession($this->session)->patchJson(tenantUrl($this->a, 'merchant/settings'), ['name' => 'Hack', 'slug' => 'tenant-b'])
        ->assertOk()->assertJsonPath('data.slug', 'tenant-a')->assertJsonPath('data.name', $this->a->name);
});

it('salva horário semanal e feriados e devolve o status', function () {
    $url = tenantUrl($this->a, 'merchant/branches/'.$this->fa->branch->public_id.'/hours');

    withSession($this->session)->putJson($url, [
        'weekly' => [
            ['weekday' => 5, 'opens_at' => '18:00', 'closes_at' => '02:00'],
            ['weekday' => 6, 'opens_at' => '18:00', 'closes_at' => '02:00'],
        ],
        'exceptions' => [['date' => now()->addDays(3)->toDateString(), 'closed' => true, 'note' => 'Feriado']],
    ])->assertOk()
        ->assertJsonCount(2, 'data.weekly')
        ->assertJsonPath('data.exceptions.0.note', 'Feriado')
        ->assertJsonStructure(['data' => ['status' => ['open', 'closes_at', 'next_opening_at']]]);

    withSession($this->session)->putJson($url, ['weekly' => [['weekday' => 9, 'opens_at' => '25:00', 'closes_at' => 'x']]])
        ->assertStatus(422);
});

it('ISOLAMENTO e escopo de unidade nos horários', function () {
    withSession($this->session)->getJson(tenantUrl($this->a, 'merchant/branches/'.$this->fb->branch->public_id.'/hours'))->assertNotFound();

    $other = inTenant($this->a, fn () => Branch::query()->create(['name' => 'Outra']));
    $manager = makeMember($this->a, User::factory()->create(), Role::MANAGER, $this->fa->branch);
    $s = withSession(merchantLogin($this->a, $manager));

    $s->getJson(tenantUrl($this->a, 'merchant/branches/'.$this->fa->branch->public_id.'/hours'))->assertOk();
    $s->putJson(tenantUrl($this->a, 'merchant/branches/'.$other->public_id.'/hours'), ['weekly' => []])->assertNotFound();
});

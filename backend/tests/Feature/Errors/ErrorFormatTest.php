<?php

use Illuminate\Support\Facades\Route;

it('propaga X-Request-Id válido e gera um quando ausente ou inseguro', function () {
    $tenant = makeTenant('tenant-a');

    $this->withHeader('X-Request-Id', 'req-12345678')->getJson(tenantUrl($tenant, 'storefront'))
        ->assertHeader('X-Request-Id', 'req-12345678');

    $generated = $this->withHeader('X-Request-Id', '<script>')->getJson(tenantUrl($tenant, 'nao-existe'));
    $generated->assertNotFound();
    expect($generated->headers->get('X-Request-Id'))->toMatch('/^[0-9A-Z]{26}$/')
        ->and($generated->json('request_id'))->toBe($generated->headers->get('X-Request-Id'));
});

it('erro interno não vaza mensagem nem stack trace', function () {
    config(['app.debug' => true]);
    Route::get('api/v1/_boom', fn () => throw new RuntimeException('segredo-interno'));

    $response = $this->getJson('http://'.config('tenancy.admin_host').'/api/v1/_boom');

    $response->assertStatus(500)->assertJsonPath('code', 'internal_error');
    expect($response->getContent())->not->toContain('segredo-interno')->not->toContain('trace');
});

<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Produção: sem página de depuração.
    config(['app.debug' => false]);
});

it('fora da API, página não encontrada sai em português e com o código do atendimento', function () {
    $response = $this->get('http://'.config('tenancy.admin_host').'/nao-existe-aqui');

    $response->assertNotFound()
        ->assertSee('Página não encontrada')
        ->assertSee('lang="pt-BR"', false)
        ->assertSee('noindex', false)
        ->assertSee('Código do atendimento: '.$response->headers->get('X-Request-Id'));
});

it('erro interno fora da API mostra página amigável sem vazar nada', function () {
    Route::get('_boom-html', fn () => throw new RuntimeException('segredo-interno'));

    $response = $this->get('http://'.config('tenancy.admin_host').'/_boom-html');

    $response->assertStatus(500)->assertSee('Algo deu errado')->assertSee('Tentar de novo');
    expect($response->getContent())->not->toContain('segredo-interno')
        ->not->toContain('RuntimeException')
        ->not->toContain('<script');
});

it('403, 419, 429 e 503 têm páginas próprias', function (int $status, string $title) {
    Route::get("_status-{$status}", fn () => abort($status));

    $this->get('http://'.config('tenancy.admin_host')."/_status-{$status}")
        ->assertStatus($status)->assertSee($title);
})->with([
    [403, 'Acesso negado'],
    [419, 'Sua sessão expirou'],
    [429, 'Muitas tentativas'],
    [503, 'Voltamos em instantes'],
]);

it('a API continua respondendo JSON, nunca HTML', function () {
    $this->get('http://'.config('tenancy.admin_host').'/api/v1/nao-existe', ['Accept' => 'text/html'])
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});

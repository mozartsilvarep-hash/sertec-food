<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Em produção (PHP-FPM) cada requisição é um processo novo; aqui o app é
     * reaproveitado entre requisições do mesmo teste. Descartamos instâncias
     * "scoped" (TenantContext, TenantAccess, FeatureGate) antes de cada
     * chamada — e o usuário em memória das guards — para reproduzir o
     * comportamento real e evitar vazamento entre requisições.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}

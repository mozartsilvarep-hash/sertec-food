<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
| Sanctum é usado no modo "SPA por sessão": a guarda sanctum consulta a
| guarda "web" (sessão). As SPAs ficam na mesma origem da API (Nginx), então
| não usamos a detecção de domínios stateful do Sanctum — o grupo de
| middleware "spa" (bootstrap/app.php) inicia a sessão explicitamente, o que
| também funciona para domínios próprios dos restaurantes.
*/
return [
    'stateful' => [],
    'guard' => ['web'],
    'expiration' => null,
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],
];

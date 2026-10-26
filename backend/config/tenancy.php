<?php

return [
    /*
    | Domínio-base dos subdomínios de plataforma: {slug}.{base_domain}.
    | Produção: cardapios.sertecsolution.com.br — Local: cardapios.localhost
    */
    'base_domain' => env('TENANCY_BASE_DOMAIN', 'cardapios.localhost'),

    /*
    | Host exclusivo do painel SerTec (superadmin). Rotas /api/v1/admin/* só
    | respondem aqui; em qualquer outro host retornam 404.
    */
    'admin_host' => env('TENANCY_ADMIN_HOST', 'admin.cardapios.localhost'),

    /*
    | Slugs que nunca podem virar tenant (colidem com hosts da plataforma).
    */
    'reserved_slugs' => [
        'admin', 'api', 'app', 'www', 'painel', 'static', 'cdn', 'assets',
        'mail', 'smtp', 'suporte', 'status', 'docs', 'blog', 'sertec',
    ],

    'default_timezone' => env('TENANCY_DEFAULT_TIMEZONE', 'America/Sao_Paulo'),

    /*
    | Formato aceito para slug: DNS label, 3-63 chars, minúsculas, dígitos e hífen.
    */
    'slug_pattern' => '/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/',

    /*
    | HTML do cardápio gerado pelo build (produção). O StorefrontShellController
    | injeta título/descrição/Open Graph do restaurante antes de entregar.
    */
    'storefront_index' => env('STOREFRONT_INDEX', '/srv/www/storefront/index.html'),
];

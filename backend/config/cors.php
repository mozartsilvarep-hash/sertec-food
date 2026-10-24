<?php

/*
| CORS desligado: SPAs e API ficam na mesma origem (Nginx). Nenhuma origem
| externa pode chamar a API com cookies do usuário. Integrações externas
| (webhooks) não dependem de CORS.
*/
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];

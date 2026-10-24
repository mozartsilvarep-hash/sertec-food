<?php

namespace App\Modules\Tenancy\Enums;

enum DomainType: string
{
    /** slug.<base_domain>: zona DNS da SerTec, verificado na criação. */
    case PlatformSubdomain = 'platform_subdomain';

    /** Domínio do cliente: só resolve após prova de posse + TLS (Sprint 5). */
    case Custom = 'custom';
}

<?php

namespace App\Modules\Identity\Enums;

/**
 * Papéis da equipe SerTec (painel de plataforma). Funcionários de
 * restaurante têm platform_role = NULL.
 */
enum PlatformRole: string
{
    case Superadmin = 'superadmin';
    case Support = 'support';
}

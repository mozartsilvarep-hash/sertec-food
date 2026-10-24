<?php

namespace App\Modules\Platform\Features;

/**
 * Funcionalidades controladas por plano (plans.features_json).
 * Toda funcionalidade comercializável nasce aqui e é checada com
 * FeatureGate::enabled() ou middleware "feature:<chave>".
 */
final class Feature
{
    /** Storefront em "modo site" (capa, sobre, galeria...). */
    public const SITE_MODE = 'site_mode';

    public const CUSTOM_DOMAIN = 'custom_domain';

    public const MULTI_BRANCH = 'multi_branch';

    public const COUPONS = 'coupons';

    public const REPORTS = 'reports';

    public const THERMAL_PRINT = 'thermal_print';

    public const WHATSAPP_NOTIFICATIONS = 'whatsapp_notifications';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SITE_MODE, self::CUSTOM_DOMAIN, self::MULTI_BRANCH, self::COUPONS,
            self::REPORTS, self::THERMAL_PRINT, self::WHATSAPP_NOTIFICATIONS,
        ];
    }
}

<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Http\Errors\ApiException;
use App\Modules\Platform\Features\FeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('feature:coupons')
 */
class RequireFeature
{
    public function __construct(private readonly FeatureGate $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $this->features->enabled($feature)) {
            throw ApiException::featureUnavailable($feature);
        }

        return $next($request);
    }
}

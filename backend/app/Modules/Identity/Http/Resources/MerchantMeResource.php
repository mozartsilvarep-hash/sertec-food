<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MerchantMeResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = app(TenantContext::class)->get();
        $access = app(TenantAccess::class)->for($this->resource);

        return [
            'user' => [
                'id' => $this->public_id,
                'name' => $this->name,
                'email' => $this->email,
            ],
            'tenant' => [
                'id' => $tenant->public_id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
            ],
            'roles' => $access->roles,
            'permissions' => $access->permissions,
            'all_branches' => $access->hasAllBranches(),
        ];
    }
}

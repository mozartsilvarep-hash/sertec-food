<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Menu */
class MenuResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'branch_id' => $this->branch?->public_id,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
        ];
    }
}

<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'menu_id' => $this->menu->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'products_count' => $this->whenCounted('placements'),
        ];
    }
}

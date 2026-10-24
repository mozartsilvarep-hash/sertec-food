<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Images\ProductImageStore;
use App\Modules\Catalog\Models\MenuProduct;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => ProductImageStore::url($this->image_key),
            'active' => $this->active,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (ProductVariant $v) => [
                'id' => $v->public_id,
                'name' => $v->name,
                'price' => $v->price,
                'active' => $v->active,
            ])->values()),
            'option_group_ids' => $this->whenLoaded('optionGroups', fn () => $this->optionGroups->map(fn (OptionGroup $g) => $g->public_id)->values()),
            'category_ids' => $this->whenLoaded('placements', fn () => $this->placements->map(fn (MenuProduct $p) => $p->category->public_id)->values()),
        ];
    }
}

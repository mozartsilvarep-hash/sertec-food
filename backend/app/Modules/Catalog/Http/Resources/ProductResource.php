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
            'code' => $this->code,
            'pos_favorite' => (bool) $this->pos_favorite,
            'image_url' => ProductImageStore::url($this->image_key),
            'thumb_url' => ProductImageStore::thumbUrl($this->image_key),
            'active' => $this->active,
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (ProductVariant $v) => [
                'id' => $v->public_id,
                'name' => $v->name,
                'price' => $v->price,
                'active' => $v->active,
            ])->values()),
            'kind' => $this->kind,
            'available_days' => $this->available_days,
            'available_from' => $this->available_from ? substr($this->available_from, 0, 5) : null,
            'available_until' => $this->available_until ? substr($this->available_until, 0, 5) : null,
            'availability' => $this->schedule()->label(),
            // Só grupos avulsos; os do combo aparecem em combo_slots.
            'option_group_ids' => $this->whenLoaded('optionGroups', fn () => $this->optionGroups->filter(fn (OptionGroup $g) => $g->owner_product_id === null)->map(fn (OptionGroup $g) => $g->public_id)->values()),
            'combo_slots' => $this->whenLoaded('optionGroups', fn () => $this->optionGroups->filter(fn (OptionGroup $g) => $g->owner_product_id === $this->id)->map(fn (OptionGroup $g) => [
                'id' => $g->public_id,
                'name' => $g->name,
                'quantity' => $g->min_select,
                'product_ids' => $g->options->map(fn ($o) => $o->product?->public_id)->filter()->values(),
            ])->values()),
            'category_ids' => $this->whenLoaded('placements', fn () => $this->placements->map(fn (MenuProduct $p) => $p->category->public_id)->values()),
        ];
    }
}

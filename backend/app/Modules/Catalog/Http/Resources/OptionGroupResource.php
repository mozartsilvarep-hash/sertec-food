<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\OptionVariantPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptionGroup */
class OptionGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'min_select' => $this->min_select,
            'max_select' => $this->max_select,
            'required' => $this->isRequired(),
            'free_quantity' => $this->free_quantity,
            'pricing_rule' => $this->pricing_rule?->value,
            'active' => $this->active,
            'options' => $this->whenLoaded('options', fn () => $this->options->map(fn (Option $o) => [
                'id' => $o->public_id,
                'name' => $o->name,
                'description' => $o->description,
                'price_delta' => $o->price_delta,
                'max_quantity' => $o->max_quantity,
                'active' => $o->active,
                'variant_prices' => $o->relationLoaded('variantPrices')
                    ? $o->variantPrices->map(fn (OptionVariantPrice $p) => ['variant' => $p->variant->public_id, 'price' => $p->price])->values()
                    : [],
            ])->values()),
        ];
    }
}

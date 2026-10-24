<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\OptionGroupType;
use App\Modules\Catalog\Models\Option;
use App\Modules\Catalog\Models\OptionGroup;
use App\Modules\Catalog\Models\OptionVariantPrice;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Support\PublicIds;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OptionGroupWriter
{
    /**
     * @param  array<string, mixed>  $data  validado por OptionGroupRequest
     */
    public function save(?OptionGroup $group, array $data): OptionGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $group ??= new OptionGroup;
            $group->fill(array_intersect_key($data, array_flip([
                'name', 'type', 'min_select', 'max_select', 'free_quantity', 'pricing_rule', 'active',
            ])));

            if ($group->type === OptionGroupType::Fraction) {
                $group->free_quantity = 0;
            } else {
                $group->pricing_rule = null;
            }

            $group->save();

            if (array_key_exists('options', $data)) {
                /** @var list<array<string, mixed>> $options */
                $options = $data['options'];
                $this->syncOptions($group, $options);
            }

            return $group->refresh()->load('options.variantPrices.variant');
        });
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(OptionGroup $group, array $options): void
    {
        $existing = $group->options()->get()->keyBy('public_id');
        $isFraction = $group->type === OptionGroupType::Fraction;
        $keep = [];

        foreach ($options as $position => $data) {
            $id = $data['id'] ?? null;
            if ($id !== null) {
                $option = $existing->get($id);
                if ($option === null) {
                    throw ValidationException::withMessages(["options.{$position}.id" => 'Opção não pertence a este grupo.']);
                }
            } else {
                $option = new Option(['option_group_id' => $group->id]);
            }

            $option->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                // Sabores não têm acréscimo: o preço vem da tabela por tamanho.
                'price_delta' => $isFraction ? '0.00' : ($data['price_delta'] ?? '0.00'),
                'max_quantity' => $isFraction ? 1 : ($data['max_quantity'] ?? 1),
                'active' => $data['active'] ?? true,
                'sort_order' => $position,
            ])->save();

            /** @var list<array{variant: string, price: string}> $prices */
            $prices = $isFraction ? ($data['variant_prices'] ?? []) : [];
            $this->syncVariantPrices($option, $prices, "options.{$position}.variant_prices");

            $keep[] = $option->id;
        }

        $group->options()->whereNotIn('id', $keep)->delete();
    }

    /**
     * @param  list<array{variant: string, price: string}>  $prices
     */
    private function syncVariantPrices(Option $option, array $prices, string $field): void
    {
        $variants = PublicIds::resolve(ProductVariant::class, array_column($prices, 'variant'), $field);

        OptionVariantPrice::query()->where('option_id', $option->id)->delete();
        foreach ($prices as $row) {
            OptionVariantPrice::query()->create([
                'option_id' => $option->id,
                'product_variant_id' => $variants[$row['variant']]->id,
                'price' => $row['price'],
            ]);
        }
    }
}

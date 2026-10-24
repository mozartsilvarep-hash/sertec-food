<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preço de um sabor (opção de grupo "fraction") num tamanho (variante).
 * Sem linha para a variante = sabor indisponível naquele tamanho.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $option_id
 * @property int $product_variant_id
 * @property string $price
 */
class OptionVariantPrice extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'option_id', 'product_variant_id', 'price'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}

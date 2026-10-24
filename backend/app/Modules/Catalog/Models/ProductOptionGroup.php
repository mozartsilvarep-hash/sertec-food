<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo produto ↔ grupo de opções (gravado por este model, nunca attach()).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int $option_group_id
 * @property int $sort_order
 */
class ProductOptionGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'option_group_id', 'sort_order'];
}

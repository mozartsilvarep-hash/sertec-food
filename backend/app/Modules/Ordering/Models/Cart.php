<?php

namespace App\Modules\Ordering\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Carrinho anônimo. Identificado por token aleatório (o banco guarda o hash).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $converted_at
 * @property string|null $order_public_id
 * @property-read Branch $branch
 * @property-read Collection<int, CartItem> $items
 */
class Cart extends Model
{
    use BelongsToTenant;

    public const TTL_HOURS = 48;

    protected $fillable = ['tenant_id', 'branch_id', 'token_hash', 'expires_at', 'converted_at', 'order_public_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'converted_at' => 'datetime'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class)->orderBy('id');
    }

    public function isUsable(): bool
    {
        return $this->converted_at === null && $this->expires_at->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}

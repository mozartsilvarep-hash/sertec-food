<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Platform\Models\Subscription;
use App\Modules\Tenancy\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Raiz do isolamento. Não usa BelongsToTenant (é o próprio tenant).
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property string $timezone
 * @property string|null $description
 * @property string|null $brand_color
 * @property string|null $logo_key
 * @property string|null $cover_key
 * @property string|null $legal_name
 * @property string|null $cnpj
 * @property string|null $instagram
 * @property string|null $whatsapp
 * @property array{text?: string, link?: string|null, link_label?: string|null, starts_at?: string|null, ends_at?: string|null}|null $announcement_json
 * @property list<string>|null $feed_origins_json
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'slug', 'status', 'timezone', 'description', 'brand_color', 'legal_name', 'cnpj', 'instagram', 'whatsapp'];

    protected $hidden = ['id'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Faixa de aviso vigente agora (null fora do período ou sem texto).
     *
     * @return array{text: string, link: string|null, link_label: string|null, starts_at: string|null, ends_at: string|null}|null
     */
    public function activeAnnouncement(?\DateTimeInterface $now = null): ?array
    {
        $a = $this->announcement_json;
        if (! is_array($a) || trim((string) ($a['text'] ?? '')) === '') {
            return null;
        }
        $now = CarbonImmutable::instance($now ?? now());
        if (! empty($a['starts_at']) && $now->lt(CarbonImmutable::parse($a['starts_at']))) {
            return null;
        }
        if (! empty($a['ends_at']) && $now->gt(CarbonImmutable::parse($a['ends_at']))) {
            return null;
        }

        return [
            'text' => (string) $a['text'],
            'link' => $a['link'] ?? null,
            'link_label' => $a['link_label'] ?? null,
            'starts_at' => $a['starts_at'] ?? null,
            'ends_at' => $a['ends_at'] ?? null,
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'announcement_json' => 'array',
            'feed_origins_json' => 'array',
        ];
    }

    /**
     * Relações abaixo são usadas apenas em modo plataforma (painel SerTec).
     *
     * @return HasMany<TenantDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}

<?php

namespace App\Modules\Payments\Models;

use App\Modules\Payments\Enums\Provider;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Conta do restaurante num provedor. `credentials` é criptografado com a
 * APP_KEY (cast encrypted:array), oculto na serialização e nunca logado.
 *
 * @property int $id
 * @property int $tenant_id
 * @property Provider $provider
 * @property array{access_token?: string, webhook_secret?: string|null}|null $credentials
 * @property string $mode
 * @property string $status
 * @property string|null $account_id
 * @property string|null $account_label
 * @property string|null $token_hint
 * @property Carbon|null $verified_at
 */
class IntegrationConnection extends Model
{
    use BelongsToTenant;

    public const ACTIVE = 'active';

    public const DISABLED = 'disabled';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['id', 'tenant_id', 'credentials'];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'credentials' => 'encrypted:array',
            'verified_at' => 'datetime',
        ];
    }

    public static function activeFor(Provider $provider): ?self
    {
        return self::query()->where('provider', $provider)->where('status', self::ACTIVE)->first();
    }

    public function accessToken(): string
    {
        return (string) ($this->credentials['access_token'] ?? '');
    }

    public function webhookSecret(): ?string
    {
        $secret = $this->credentials['webhook_secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function isTest(): bool
    {
        return $this->mode === 'test';
    }
}

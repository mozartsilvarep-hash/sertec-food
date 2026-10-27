<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração da plataforma SerTec (sem tenant). O valor é criptografado
 * com a APP_KEY e nunca serializado.
 *
 * @property string $key
 * @property array<string, mixed> $value
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $key): ?array
    {
        return self::query()->find($key)?->value;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public static function write(string $key, ?array $value): void
    {
        if ($value === null) {
            self::query()->whereKey($key)->delete();

            return;
        }
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo global de permissões (não pertence a tenant).
 *
 * @property int $id
 * @property string $key
 * @property string|null $description
 */
class Permission extends Model
{
    protected $fillable = ['key', 'description'];
}

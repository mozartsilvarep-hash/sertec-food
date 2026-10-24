<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Permissions;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::defaultRoles() as $key => $definition) {
            $role = Role::query()->whereNull('tenant_id')->where('key', $key)->first()
                ?? new Role(['tenant_id' => null, 'key' => $key]);
            $role->name = $definition['name'];
            $role->save();

            $ids = Permission::query()->whereIn('key', $definition['permissions'])->pluck('id');
            $role->permissions()->sync($ids);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Modules\Identity\Models\Permission;
use App\Modules\Identity\Permissions;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::all() as $key => $description) {
            Permission::query()->updateOrCreate(['key' => $key], ['description' => $description]);
        }
    }
}

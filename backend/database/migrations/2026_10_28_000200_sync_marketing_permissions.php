<?php

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

/** Nova permissão marketing.manage (proprietário e gerente). */
return new class extends Migration
{
    public function up(): void
    {
        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        //
    }
};

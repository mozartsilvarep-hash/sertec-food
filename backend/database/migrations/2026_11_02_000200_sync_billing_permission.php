<?php

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

/** billing.manage: ver e pagar a mensalidade da SerTec (só o proprietário). */
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

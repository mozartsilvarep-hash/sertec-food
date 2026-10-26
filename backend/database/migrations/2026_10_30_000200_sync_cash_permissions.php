<?php

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

/** Permissões do caixa: cash.operate (atendente, gerente, dono) e cash.manage (gerente, dono). */
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

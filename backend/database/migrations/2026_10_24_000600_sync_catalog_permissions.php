<?php

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Catálogo de permissões é dado de referência: ambientes já instalados
 * recebem a nova permissão catalog.availability sem rodar db:seed.
 * Seeders são idempotentes; rollback não remove permissões.
 */
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

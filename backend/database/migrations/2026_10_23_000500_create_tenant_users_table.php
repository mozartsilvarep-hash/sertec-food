<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Papel global (roles.tenant_id NULL) ou do mesmo tenant: regra validada
            // na aplicação (TenantUser::saving), pois FK composta não expressa "OU".
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            // NULL = acesso a todas as unidades do tenant.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('branch_scope')->storedAs('COALESCE(branch_id, 0)');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // FK composta: a unidade precisa pertencer ao MESMO tenant do vínculo.
            $table->foreign(['tenant_id', 'branch_id'], 'tenant_users_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')
                ->restrictOnDelete();

            // MariaDB trata NULLs como distintos; branch_scope evita vínculos
            // duplicados (tenant, usuário, "todas as unidades").
            $table->unique(['tenant_id', 'user_id', 'branch_scope'], 'tenant_users_unique_membership');
            $table->index(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
    }
};

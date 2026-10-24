<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Sempre em minúsculas, sem porta. Único na plataforma inteira.
            $table->string('hostname', 253)->unique();
            // platform_subdomain: slug.<base_domain> (zona nossa, verificado na criação)
            // custom: domínio do cliente, só resolve após prova de posse (verified_at)
            $table->string('type', 30)->default('platform_subdomain');
            $table->string('verification_token_hash', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            // No máximo um domínio primário por tenant: NULL não colide em UNIQUE.
            $table->unsignedBigInteger('primary_tenant_id')
                ->nullable()
                ->storedAs('IF(is_primary, tenant_id, NULL)');
            $table->timestamps();

            $table->unique('primary_tenant_id', 'tenant_domains_one_primary_per_tenant');
            $table->unique(['tenant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};

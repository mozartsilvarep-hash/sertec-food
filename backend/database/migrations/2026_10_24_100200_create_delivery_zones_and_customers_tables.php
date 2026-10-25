<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Zona de entrega por bairro (raio/polígono dependem de geocodificação — pendente).
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('name', 80);
            // Lista de bairros atendidos ["Ponta Verde", "Pajuçara"].
            $table->json('neighborhoods_json');
            $table->decimal('fee', 12, 2);
            $table->decimal('minimum_order', 12, 2)->nullable();
            $table->decimal('free_above', 12, 2)->nullable();
            $table->unsignedSmallInteger('eta_minutes')->default(45);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'active']);
            $table->foreign(['tenant_id', 'branch_id'], 'delivery_zones_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        // Cliente do estabelecimento (sem login). Dados mínimos (LGPD).
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 120);
            // Somente dígitos, com DDD (10–11). Identifica o cliente dentro do tenant.
            $table->string('phone', 20);
            $table->string('email', 191)->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('delivery_zones');
    }
};

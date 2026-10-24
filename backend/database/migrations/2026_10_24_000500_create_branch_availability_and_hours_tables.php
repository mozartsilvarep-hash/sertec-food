<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ausência de linha = disponível. Linha com available=false = esgotado na unidade.
        Schema::create('branch_product_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->boolean('available')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'branch_id'], 'bpa_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'bpa_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        // Horário semanal no fuso do tenant. closes_at <= opens_at = termina no dia seguinte.
        Schema::create('branch_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            // 0 = domingo … 6 = sábado
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'weekday']);
            $table->foreign(['tenant_id', 'branch_id'], 'boh_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        // Feriados/datas especiais: substituem o horário semanal daquela data.
        Schema::create('branch_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->date('date');
            $table->boolean('closed')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->string('note', 120)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'date']);
            $table->foreign(['tenant_id', 'branch_id'], 'bse_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_schedule_exceptions');
        Schema::dropIfExists('branch_opening_hours');
        Schema::dropIfExists('branch_product_availability');
    }
};

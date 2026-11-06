<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estoque simples (Sprint 16): quantidade por produto e unidade, com
 * histórico. Só produtos com linha em product_stocks são controlados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity')->default(0);
            // Abaixo (ou igual) disto aparece como "estoque baixo".
            $table->unsignedInteger('min_quantity')->default(0);
            // Esgotado marcado pelo estoque (volta sozinho quando repõe).
            $table->boolean('auto_paused')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'product_id']);
            $table->foreign(['tenant_id', 'branch_id'], 'product_stocks_tenant_branch_fk')->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'product_stocks_tenant_product_fk')->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            // sale | cancel | entry | loss | count
            $table->string('type', 10);
            $table->integer('quantity'); // com sinal: saída negativa
            $table->integer('balance_after');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 140)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'branch_id', 'product_id', 'id']);
            $table->index(['tenant_id', 'order_id']);
            $table->foreign(['tenant_id', 'branch_id'], 'stock_movements_tenant_branch_fk')->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'stock_movements_tenant_product_fk')->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_stocks');
    }
};

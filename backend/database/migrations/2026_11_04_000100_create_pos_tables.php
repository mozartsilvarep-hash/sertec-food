<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDV completo (Sprint 14): venda de balcão vira pedido de verdade
 * (cozinha, relatórios, fidelidade), com várias formas de pagamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // online = cardápio; pos = PDV do restaurante.
            $table->string('channel', 10)->default('online')->after('branch_id');
            $table->foreignId('created_by')->nullable()->after('channel')->constrained('users')->nullOnDelete();
            // Troco devolvido no PDV (dinheiro recebido − parte em dinheiro).
            $table->decimal('change_given', 12, 2)->nullable()->after('change_for');
            $table->index(['tenant_id', 'channel', 'placed_at']);
        });

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            // Mesmas formas do caixa: cash, pix, debit, credit, other.
            $table->string('method', 20);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->foreign(['tenant_id', 'order_id'], 'order_payments_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'order_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            // Código curto para digitar no PDV (ex.: 12, COCA).
            $table->string('code', 20)->nullable()->after('name');
            // Botão grande na tela de venda rápida.
            $table->boolean('pos_favorite')->default(false)->after('code');
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropColumn(['code', 'pos_favorite']);
        });
        Schema::dropIfExists('order_payments');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'channel', 'placed_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['channel', 'change_given']);
        });
    }
};

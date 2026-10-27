<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financeiro (Sprint 15): taxa de cada forma de pagamento (maquininha, Pix,
 * site), custos fixos do mês e a taxa calculada em cada entrada do caixa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Forma do caixa: pix, debit, credit, card, online, other, cash.
            $table->string('method', 10);
            // Percentual em pontos-base (2,99% = 299) + valor fixo por venda.
            $table->unsignedInteger('percent_bp')->default(0);
            $table->decimal('fixed_amount', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'method']);
        });

        Schema::create('fixed_costs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // null = custo do restaurante todo; senão, de uma unidade.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name', 80);
            $table->string('category', 20);
            $table->decimal('amount', 12, 2); // valor por mês
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'active']);
            $table->foreign(['tenant_id', 'branch_id'], 'fixed_costs_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::table('cash_entries', function (Blueprint $table) {
            // Taxa da maquininha/Pix/site sobre esta entrada (calculada ao lançar).
            $table->decimal('fee_amount', 12, 2)->default(0)->after('amount');
            // Saída que paga um custo fixo (não conta duas vezes no resultado).
            $table->unsignedBigInteger('fixed_cost_id')->nullable()->after('order_id');
            $table->foreign(['tenant_id', 'fixed_cost_id'], 'cash_entries_tenant_fixed_cost_fk')
                ->references(['tenant_id', 'id'])->on('fixed_costs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cash_entries', function (Blueprint $table) {
            $table->dropForeign('cash_entries_tenant_fixed_cost_fk');
            $table->dropColumn(['fee_amount', 'fixed_cost_id']);
        });
        Schema::dropIfExists('fixed_costs');
        Schema::dropIfExists('payment_fees');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relatórios e financeiro de "todas as unidades" filtram só por período:
 * índices por (tenant, data) evitam varrer todos os pedidos/lançamentos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->index(['tenant_id', 'placed_at'], 'orders_tenant_placed_idx'));
        Schema::table('cash_entries', fn (Blueprint $t) => $t->index(['tenant_id', 'occurred_at'], 'cash_entries_tenant_occurred_idx'));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropIndex('orders_tenant_placed_idx'));
        Schema::table('cash_entries', fn (Blueprint $t) => $t->dropIndex('cash_entries_tenant_occurred_idx'));
    }
};

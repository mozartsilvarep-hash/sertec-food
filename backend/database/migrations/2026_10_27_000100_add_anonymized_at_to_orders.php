<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LGPD: marca pedidos cujos dados pessoais foram removidos (retenção vencida
 * ou pedido do titular). Valores e itens continuam para a contabilidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('anonymized_at')->nullable()->after('cancelled_at');
            $table->index(['anonymized_at', 'placed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['anonymized_at', 'placed_at']);
            $table->dropColumn('anonymized_at');
        });
    }
};

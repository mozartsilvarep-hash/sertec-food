<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueio automático por atraso (D101): 7 dias depois do vencimento a
 * loja online, o PDV e as mesas param; pagou, volta na hora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('paid_until');
            // A SerTec pode tirar um cliente do bloqueio automático.
            $table->boolean('auto_block')->default(true)->after('blocked_at');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('reminded_block_at')->nullable()->after('reminded_overdue_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('reminded_block_at'));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['blocked_at', 'auto_block']));
    }
};

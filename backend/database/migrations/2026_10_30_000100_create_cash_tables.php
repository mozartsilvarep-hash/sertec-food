<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 10 — caixa (livro-caixa e mini PDV) por unidade.
 * Lançamentos nunca são apagados: são estornados (voided_at) com motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_amount', 12, 2)->nullable();  // dinheiro esperado na gaveta
            $table->decimal('counted_amount', 12, 2)->nullable();   // contado no fechamento
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'closed_at']);
            $table->foreign(['tenant_id', 'branch_id'], 'cash_sessions_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('cash_session_id')->nullable();
            $table->string('type', 3);              // in | out
            $table->string('category', 20);
            $table->string('method', 10);           // cash, pix, debit, credit, card, online, other
            $table->decimal('amount', 12, 2);       // sempre positivo
            $table->string('description', 200)->nullable();
            $table->json('items_json')->nullable(); // venda de balcão: itens
            $table->unsignedBigInteger('order_id')->nullable();
            // Origem automática única: "order:ID" / "refund:ID" (evita duplicar).
            $table->string('source', 40)->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'source']);
            $table->index(['tenant_id', 'branch_id', 'occurred_at']);
            $table->index(['tenant_id', 'cash_session_id']);
            $table->foreign(['tenant_id', 'branch_id'], 'cash_entries_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_session_id'], 'cash_entries_tenant_session_fk')
                ->references(['tenant_id', 'id'])->on('cash_sessions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'order_id'], 'cash_entries_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });

        // Caixa liberado em todos os planos existentes (brinde); a SerTec pode
        // tirar de um plano depois (recurso "cashbook").
        foreach (DB::table('plans')->get(['id', 'features_json']) as $plan) {
            $features = json_decode((string) $plan->features_json, true) ?: [];
            $features['cashbook'] = true;
            DB::table('plans')->where('id', $plan->id)->update(['features_json' => json_encode($features)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_sessions');
    }
};

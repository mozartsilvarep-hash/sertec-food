<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 11 — cartão fidelidade por celular.
 * loyalty_programs: regra da loja (1 por restaurante).
 * loyalty_cards: saldo atual (selos e prêmios) por celular.
 * loyalty_ledger: histórico (selo ganho, prêmio usado, estornos, ajustes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->boolean('active')->default(false);
            $table->unsignedTinyInteger('stamps_required')->default(10);
            $table->decimal('min_order', 12, 2)->nullable();       // pedido mínimo para ganhar selo
            $table->string('reward_type', 10)->default('fixed');   // fixed | percent
            $table->decimal('reward_amount', 12, 2)->nullable();   // fixed: R$
            $table->unsignedSmallInteger('reward_percent_bp')->nullable(); // percent
            $table->decimal('reward_max', 12, 2)->nullable();      // teto do percentual
            $table->timestamps();
        });

        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('phone', 20);
            $table->unsignedSmallInteger('stamps')->default(0);
            $table->unsignedSmallInteger('rewards')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'phone']);
        });

        Schema::create('loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('loyalty_card_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('type', 20); // stamp | reward_used | reward_restored | stamp_reverted | adjust
            $table->smallInteger('stamps_delta')->default(0);
            $table->smallInteger('rewards_delta')->default(0);
            $table->decimal('amount', 12, 2)->nullable(); // desconto usado
            $table->string('note', 200)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'loyalty_card_id']);
            // Um selo e um uso de prêmio por pedido (idempotência).
            $table->unique(['tenant_id', 'order_id', 'type']);
            $table->foreign(['tenant_id', 'loyalty_card_id'], 'loyalty_ledger_tenant_card_fk')
                ->references(['tenant_id', 'id'])->on('loyalty_cards')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'order_id'], 'loyalty_ledger_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });

        // Fidelidade liberada em todos os planos (brinde), desligável por plano.
        foreach (DB::table('plans')->get(['id', 'features_json']) as $plan) {
            $features = json_decode((string) $plan->features_json, true) ?: [];
            $features['loyalty'] = true;
            DB::table('plans')->where('id', $plan->id)->update(['features_json' => json_encode($features)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_ledger');
        Schema::dropIfExists('loyalty_cards');
        Schema::dropIfExists('loyalty_programs');
    }
};

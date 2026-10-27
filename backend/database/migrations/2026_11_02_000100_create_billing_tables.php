<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cobrança da SerTec (Sprint 13): preço do plano por período, mensalidade de
 * cada restaurante, faturas pagas por Pix na conta Mercado Pago da SerTec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('name');
            // {"1": "99.00", "3": "279.00", "6": null, "12": "990.00"} — meses => preço do período.
            $table->json('prices_json')->nullable()->after('monthly_price');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_months')->default(1)->after('status');
            // null = preço do plano para o período; 0.00 = cortesia (sem cobrança).
            $table->decimal('price', 12, 2)->nullable()->after('billing_months');
            // Último dia já pago (inclusive). null = ainda sem pagamento.
            $table->date('paid_until')->nullable()->after('period_end');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('plan_name', 120);
            $table->unsignedTinyInteger('months');
            $table->decimal('amount', 12, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            // open | paid | cancelled
            $table->string('status', 12)->default('open');
            $table->timestamp('paid_at')->nullable();
            // pix | manual
            $table->string('paid_via', 12)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('mp_payment_id', 40)->nullable()->unique();
            $table->text('pix_code')->nullable();
            $table->mediumText('pix_qr_base64')->nullable();
            $table->timestamp('pix_expires_at')->nullable();
            $table->unsignedSmallInteger('pix_attempts')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('reminded_due_at')->nullable();
            $table->timestamp('reminded_overdue_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['subscription_id', 'period_start']);
            $table->index(['status', 'due_date']);
        });

        // Configurações da plataforma (valor criptografado com a APP_KEY).
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value');
            $table->timestamps();
        });

        // Caixa de entrada dos webhooks da conta SerTec (deduplicação).
        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('external_event_id', 100)->unique();
            $table->string('resource_id', 64);
            $table->boolean('signature_valid')->default(false);
            $table->string('status', 12)->default('received');
            $table->timestamps();
        });

        // Preço mensal já cadastrado vira o preço do período de 1 mês.
        foreach (DB::table('plans')->get(['id', 'monthly_price']) as $plan) {
            if ((float) $plan->monthly_price > 0) {
                DB::table('plans')->where('id', $plan->id)->update(['prices_json' => json_encode(['1' => (string) $plan->monthly_price])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('invoices');
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['billing_months', 'price', 'paid_until']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['description', 'prices_json']));
    }
};

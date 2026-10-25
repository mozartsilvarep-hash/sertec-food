<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma tentativa de cobrança online de um pedido. O pedido só vira "pago"
        // quando a API do provedor confirma (webhook ou reconciliação).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Enviado ao provedor como external_reference e X-Idempotency-Key.
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('provider', 30);
            $table->string('method', 30); // pix | online_card
            $table->string('external_id', 64)->nullable();
            $table->string('preference_id', 80)->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_refunded', 12, 2)->default(0);
            $table->string('status', 30);
            $table->string('provider_status', 40)->nullable();
            $table->string('provider_status_detail', 80)->nullable();
            // Dados para o cliente pagar (não são segredos do restaurante).
            $table->text('pix_qr_code')->nullable();
            $table->mediumText('pix_qr_base64')->nullable();
            $table->string('ticket_url', 500)->nullable();
            $table->string('checkout_url', 500)->nullable();
            $table->string('last_error', 80)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['provider', 'external_id']);
            $table->index(['status', 'expires_at']);
            $table->index(['tenant_id', 'order_id']);
            $table->foreign(['tenant_id', 'order_id'], 'payments_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });

        // Caixa de entrada de webhooks: grava, deduplica e processa em fila.
        // Guarda só o mínimo para reprocessar (tipo, ação, id do recurso).
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('external_event_id', 100);
            $table->string('topic', 40);
            $table->string('action', 60)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->boolean('live_mode')->nullable();
            $table->boolean('signature_valid');
            $table->string('status', 20); // received | processed | ignored | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 120)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['provider', 'external_event_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('payment_id');
            $table->string('external_id', 64)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20); // pending | approved | rejected | failed
            $table->string('reason', 200)->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_error', 80)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['external_id']);
            $table->foreign(['tenant_id', 'payment_id'], 'refunds_tenant_payment_fk')
                ->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};

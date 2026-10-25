<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Numeração visível por unidade (#1, #2…), incrementada com trava de linha.
        Schema::create('order_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'branch_id']);
            $table->foreign(['tenant_id', 'branch_id'], 'order_sequences_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedInteger('order_number');
            $table->char('tracking_token_hash', 64)->unique();

            // Estado operacional e de pagamento são independentes (ARCHITECTURE.md).
            $table->string('status', 30);
            $table->string('payment_status', 30);
            $table->string('payment_method', 30);
            $table->decimal('change_for', 12, 2)->nullable();
            $table->string('fulfillment_type', 20);

            // Snapshots do momento da compra.
            $table->string('customer_name', 120);
            $table->string('customer_phone', 20);
            $table->json('address_snapshot_json')->nullable();
            $table->string('delivery_zone_name', 80)->nullable();
            $table->string('notes', 300)->nullable();

            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total', 12, 2);

            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->string('cancellation_reason', 40)->nullable();
            $table->string('cancellation_note', 300)->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'order_number']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'branch_id', 'placed_at']);
            $table->foreign(['tenant_id', 'branch_id'], 'orders_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'], 'orders_tenant_customer_fk')
                ->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            // Referência informativa; o pedido vale pelos snapshots abaixo.
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name_snapshot', 150);
            $table->string('variant_name_snapshot', 80);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->string('notes', 140)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'order_id']);
            $table->foreign(['tenant_id', 'order_id'], 'order_items_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'order_items_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('order_item_id');
            $table->string('group_name_snapshot', 120);
            $table->string('option_name_snapshot', 120);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('charged_quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total', 12, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'order_item_id']);
            $table->foreign(['tenant_id', 'order_item_id'], 'order_item_options_tenant_item_fk')
                ->references(['tenant_id', 'id'])->on('order_items')->cascadeOnDelete();
        });

        // Composição do total, na ordem em que foi calculada.
        Schema::create('order_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('code', 30);
            $table->string('label', 80);
            $table->decimal('amount', 12, 2);
            $table->unsignedTinyInteger('sort_order');
            $table->timestamps();

            $table->index(['tenant_id', 'order_id']);
            $table->foreign(['tenant_id', 'order_id'], 'order_totals_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('order_id');
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'order_id']);
            $table->foreign(['tenant_id', 'order_id'], 'order_status_history_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->cascadeOnDelete();
        });

        // Checkout idempotente: mesma chave = mesma resposta, sem novo pedido.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('key', 100);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status_code');
            $table->json('response_json');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'key']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_totals');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_sequences');
    }
};

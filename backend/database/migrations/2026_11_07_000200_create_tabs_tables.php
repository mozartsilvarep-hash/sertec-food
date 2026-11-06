<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesas e comandas (Sprint 16): a conta fica aberta recebendo itens e,
 * ao fechar, vira uma venda do PDV (pedido + caixa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tabs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('label', 40); // "Mesa 4", "Comanda 12"
            // Igual ao label (normalizado) enquanto aberta: impede duas mesas 4 abertas.
            $table->string('open_key', 40)->nullable();
            $table->string('customer_name', 120)->nullable();
            $table->unsignedSmallInteger('people')->default(1);
            $table->string('status', 10)->default('open'); // open | closed | cancelled
            $table->unsignedSmallInteger('kitchen_batches')->default(0);
            $table->string('notes', 300)->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'open_key']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->foreign(['tenant_id', 'branch_id'], 'tabs_tenant_branch_fk')->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'order_id'], 'tabs_tenant_order_fk')->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });

        Schema::create('tab_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('tab_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->json('options_json')->nullable();
            $table->string('name', 160);
            $table->string('detail', 300)->nullable();
            // Preço de referência (o oficial é recalculado ao fechar).
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->string('notes', 140)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('kitchen_batch')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 140)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'tab_id']);
            $table->foreign(['tenant_id', 'tab_id'], 'tab_items_tenant_tab_fk')->references(['tenant_id', 'id'])->on('tabs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'tab_items_tenant_product_fk')->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // Baixa feita ao lançar na comanda (o pedido só nasce ao fechar).
            $table->unsignedBigInteger('tab_item_id')->nullable()->after('order_id');
            $table->index(['tenant_id', 'tab_item_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Mesa/comanda de origem (aparece no recibo e no pedido).
            $table->string('table_label', 40)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('table_label'));
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'tab_item_id']);
            $t->dropColumn('tab_item_id');
        });
        Schema::dropIfExists('tab_items');
        Schema::dropIfExists('tabs');
    }
};

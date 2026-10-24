<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            // O cliente guarda o token; o banco guarda só o SHA-256.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('converted_at')->nullable();
            $table->ulid('order_public_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['expires_at']);
            $table->foreign(['tenant_id', 'branch_id'], 'carts_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->unsignedSmallInteger('quantity');
            // [{option: ULID, quantity: int}] — preço NUNCA é guardado aqui.
            $table->json('selections_json');
            $table->string('notes', 140)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'cart_id']);
            $table->foreign(['tenant_id', 'cart_id'], 'cart_items_tenant_cart_fk')
                ->references(['tenant_id', 'id'])->on('carts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'cart_items_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'], 'cart_items_tenant_variant_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};

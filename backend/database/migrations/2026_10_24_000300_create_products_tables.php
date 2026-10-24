<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            // Chave no disco (nunca o nome enviado pelo cliente).
            $table->string('image_key', 191)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            // Arquivado, não apagado: pedidos antigos guardam snapshot (Sprint 2).
            $table->softDeletes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'active', 'name']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            // Ex.: "Única", "Média", "Grande". Todo produto tem ao menos uma.
            $table->string('name', 80);
            $table->decimal('price', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'product_id', 'sort_order']);
            $table->foreign(['tenant_id', 'product_id'], 'product_variants_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        // Produto posicionado numa categoria (o cardápio vem da categoria).
        Schema::create('menu_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'category_id'], 'menu_products_tenant_category_fk')
                ->references(['tenant_id', 'id'])->on('categories')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'menu_products_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_products');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};

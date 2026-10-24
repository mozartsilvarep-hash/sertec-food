<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 120);
            // addon: soma price_delta × quantidade | fraction: sabores (meio a meio)
            $table->string('type', 20)->default('addon');
            // Obrigatório = min_select > 0.
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            // addon: as N primeiras unidades (mais baratas) não são cobradas.
            $table->unsignedTinyInteger('free_quantity')->default(0);
            // fraction: highest (sabor mais caro) | average (média dos sabores)
            $table->string('pricing_rule', 20)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('option_group_id');
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->unsignedTinyInteger('max_quantity')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'option_group_id', 'sort_order']);
            $table->foreign(['tenant_id', 'option_group_id'], 'options_tenant_group_fk')
                ->references(['tenant_id', 'id'])->on('option_groups')->cascadeOnDelete();
        });

        // Preço de cada sabor por tamanho (grupos "fraction").
        Schema::create('option_variant_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('option_id');
            $table->unsignedBigInteger('product_variant_id');
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['option_id', 'product_variant_id']);
            $table->index(['tenant_id', 'product_variant_id']);
            $table->foreign(['tenant_id', 'option_id'], 'ovp_tenant_option_fk')
                ->references(['tenant_id', 'id'])->on('options')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'], 'ovp_tenant_variant_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('product_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('option_group_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'option_group_id']);
            $table->index(['tenant_id', 'option_group_id']);
            $table->foreign(['tenant_id', 'product_id'], 'pog_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'option_group_id'], 'pog_tenant_group_fk')
                ->references(['tenant_id', 'id'])->on('option_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('option_variant_prices');
        Schema::dropIfExists('options');
        Schema::dropIfExists('option_groups');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — cardápio:
 * - produto disponível só em certos dias da semana e/ou horário;
 * - combos (produto com "espaços" preenchidos por produtos do cardápio);
 * - promoção "leve N, pague M".
 * Só acrescenta colunas: nenhum dado existente muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('kind', 10)->default('item')->after('description'); // item | combo
            $table->json('available_days')->nullable()->after('active');        // [0..6] (0 = domingo); null = todos
            $table->time('available_from')->nullable()->after('available_days');
            $table->time('available_until')->nullable()->after('available_from');
        });

        Schema::table('option_groups', function (Blueprint $table) {
            // Grupo que pertence a um combo (não aparece na lista geral de opções).
            $table->unsignedBigInteger('owner_product_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'owner_product_id']);
            $table->foreign(['tenant_id', 'owner_product_id'], 'option_groups_tenant_owner_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::table('options', function (Blueprint $table) {
            // Opção que representa um produto do cardápio (itens de combo).
            $table->unsignedBigInteger('product_id')->nullable()->after('option_group_id');
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'product_id'], 'options_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->string('type', 10)->default('percent')->after('product_id'); // percent | bundle
            $table->unsignedTinyInteger('buy_quantity')->nullable()->after('percent_bp'); // leve N
            $table->unsignedTinyInteger('pay_quantity')->nullable()->after('buy_quantity'); // pague M
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['type', 'buy_quantity', 'pay_quantity']);
        });
        Schema::table('options', function (Blueprint $table) {
            $table->dropForeign('options_tenant_product_fk');
            $table->dropIndex(['tenant_id', 'product_id']);
            $table->dropColumn('product_id');
        });
        Schema::table('option_groups', function (Blueprint $table) {
            $table->dropForeign('option_groups_tenant_owner_fk');
            $table->dropIndex(['tenant_id', 'owner_product_id']);
            $table->dropColumn('owner_product_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['kind', 'available_days', 'available_from', 'available_until']);
        });
    }
};

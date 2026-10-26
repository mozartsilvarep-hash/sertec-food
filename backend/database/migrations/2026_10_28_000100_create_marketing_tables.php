<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promoção por produto: desconto percentual (em centésimos de %) sobre o
        // preço unitário calculado (variante + sabores + adicionais).
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedSmallInteger('percent_bp'); // 1..9000 = 0,01%..90%
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('featured')->default(false);
            $table->string('badge', 18)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'product_id', 'active']);
            $table->foreign(['tenant_id', 'product_id'], 'promotions_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('code', 30); // sempre MAIÚSCULAS
            $table->string('description', 120)->nullable();
            $table->string('discount_type', 20); // percent | fixed | free_delivery
            $table->unsignedSmallInteger('percent_bp')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('max_discount', 12, 2)->nullable();
            $table->decimal('min_subtotal', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedSmallInteger('per_customer_limit')->nullable();
            $table->boolean('first_order_only')->default(false);
            $table->unsignedInteger('times_used')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('order_id');
            $table->string('customer_phone', 20);
            $table->decimal('amount', 12, 2);
            $table->string('status', 10); // applied | voided
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'coupon_id', 'customer_phone', 'status'], 'coupon_redemptions_customer_idx');
            $table->foreign(['tenant_id', 'coupon_id'], 'coupon_redemptions_tenant_coupon_fk')
                ->references(['tenant_id', 'id'])->on('coupons')->restrictOnDelete();
            $table->foreign(['tenant_id', 'order_id'], 'coupon_redemptions_tenant_order_fk')
                ->references(['tenant_id', 'id'])->on('orders')->restrictOnDelete();
        });

        // Vitrine: faixa de aviso e sites autorizados a ler o feed (CORS).
        Schema::table('tenants', function (Blueprint $table) {
            $table->json('announcement_json')->nullable()->after('brand_color');
            $table->json('feed_origins_json')->nullable()->after('announcement_json');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['announcement_json', 'feed_origins_json']);
        });
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('promotions');
    }
};

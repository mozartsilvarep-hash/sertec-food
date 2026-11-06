<?php

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garçom no celular e QR Code na mesa (D103): mesas cadastradas com um
 * código para o QR; o cliente pede pelo cardápio e o garçom confirma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('label', 40);
            // Vai no QR (aleatório; trocar invalida os QR impressos).
            $table->char('qr_token', 24)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'branch_id', 'label']);
            $table->foreign(['tenant_id', 'branch_id'], 'dining_tables_tenant_branch_fk')->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });

        Schema::create('tab_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('dining_table_id');
            $table->unsignedBigInteger('tab_id')->nullable();
            $table->string('type', 10)->default('order'); // order | waiter | bill
            $table->json('lines_json')->nullable();
            $table->string('summary', 500)->nullable();
            $table->string('customer_name', 60)->nullable();
            $table->string('notes', 200)->nullable();
            $table->string('status', 10)->default('pending'); // pending | accepted | rejected | expired
            $table->char('view_token_hash', 64);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 140)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'status']);
            $table->index(['tenant_id', 'dining_table_id', 'created_at']);
            $table->foreign(['tenant_id', 'dining_table_id'], 'tab_requests_tenant_table_fk')->references(['tenant_id', 'id'])->on('dining_tables')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'tab_id'], 'tab_requests_tenant_tab_fk')->references(['tenant_id', 'id'])->on('tabs')->restrictOnDelete();
        });

        Schema::table('tabs', function (Blueprint $table) {
            // Cliente ou garçom pediu a conta (o caixa vê na lista).
            $table->timestamp('bill_requested_at')->nullable()->after('kitchen_batches');
        });

        (new PermissionSeeder)->run();
        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('tabs', fn (Blueprint $t) => $t->dropColumn('bill_requested_at'));
        Schema::dropIfExists('tab_requests');
        Schema::dropIfExists('dining_tables');
    }
};

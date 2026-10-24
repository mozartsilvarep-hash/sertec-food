<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // NULL = cardápio de todas as unidades.
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'branch_id'], 'menus_tenant_branch_fk')
                ->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('menu_id');
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'menu_id', 'sort_order']);
            $table->foreign(['tenant_id', 'menu_id'], 'categories_tenant_menu_fk')
                ->references(['tenant_id', 'id'])->on('menus')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
        Schema::dropIfExists('menus');
    }
};

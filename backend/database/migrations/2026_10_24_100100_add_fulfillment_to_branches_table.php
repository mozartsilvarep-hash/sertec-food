<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('pickup_enabled')->default(true)->after('active');
            $table->boolean('delivery_enabled')->default(false)->after('pickup_enabled');
            $table->unsignedSmallInteger('pickup_eta_minutes')->default(30)->after('delivery_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['pickup_enabled', 'delivery_enabled', 'pickup_eta_minutes']);
        });
    }
};

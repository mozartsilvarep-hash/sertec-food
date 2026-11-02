<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Tema do cardápio (cores, fontes, cantos): ver StoreTheme.
            $table->json('theme_json')->nullable()->after('brand_color');
            // Padronagem (azulejo quadrado em WebP) do topo do cardápio sem capa.
            $table->string('pattern_key')->nullable()->after('cover_key');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['theme_json', 'pattern_key']);
        });
    }
};

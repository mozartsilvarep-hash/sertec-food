<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Logo (quadrada) e capa (larga) em WebP no disco público.
            $table->string('logo_key')->nullable()->after('brand_color');
            $table->string('cover_key')->nullable()->after('logo_key');
            // Identificação exigida no comércio eletrônico (Decreto 7.962/2013).
            $table->string('legal_name', 150)->nullable()->after('cover_key');
            // Só os 14 caracteres (aceita o CNPJ alfanumérico).
            $table->char('cnpj', 14)->nullable()->after('legal_name');
            $table->string('instagram', 30)->nullable()->after('cnpj');
            $table->string('whatsapp', 13)->nullable()->after('instagram');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['logo_key', 'cover_key', 'legal_name', 'cnpj', 'instagram', 'whatsapp']);
        });
    }
};

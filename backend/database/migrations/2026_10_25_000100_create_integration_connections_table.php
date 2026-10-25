<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credenciais de integrações por restaurante (Mercado Pago, depois WhatsApp).
        // `credentials` é criptografado pela aplicação (APP_KEY) e nunca sai na API.
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 30);
            $table->text('credentials')->nullable();
            $table->string('mode', 10); // test | live
            $table->string('status', 20); // active | disabled
            // Dados não secretos da conta, para conferência no painel e nos pagamentos.
            $table->string('account_id', 40)->nullable();
            $table->string('account_label', 120)->nullable();
            $table->string('token_hint', 8)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};

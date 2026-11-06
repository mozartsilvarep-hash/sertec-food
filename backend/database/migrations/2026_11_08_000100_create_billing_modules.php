<?php

use App\Modules\Platform\Modules\ModuleKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulos vendidos separadamente (D100): cada cliente contrata só o que
 * quer (Cardápio digital, PDV e caixa, Mesas e comandas, Estoque); a
 * mensalidade soma os módulos no ciclo do cliente (mensal ou anual).
 *
 * Clientes atuais ficam com todos os módulos e com o valor de hoje
 * congelado em "valor próprio" (o preço dos módulos, definido depois, não
 * muda o deles; quem não pagava nada fica com 0,00 até a SerTec ajustar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 20)->unique();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            // Preço por período em meses: {"1": "49.90", "12": "499.00"}.
            $table->json('prices_json')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Lista de ModuleKey contratados; null = só o Cardápio digital.
            $table->json('modules_json')->nullable()->after('plan_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Composição da fatura: [{label, amount}].
            $table->json('lines_json')->nullable()->after('amount');
        });

        $now = Carbon::now();
        foreach (ModuleKey::cases() as $i => $m) {
            DB::table('billing_modules')->insert([
                'key' => $m->value, 'name' => $m->label(), 'description' => $m->description(),
                'prices_json' => json_encode(['1' => null, '12' => null]), 'active' => true, 'sort_order' => $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // O plano agora é só "recursos extras": nomes que não confundem com o
        // módulo Cardápio digital (só se ninguém renomeou no painel).
        DB::table('plans')->where('slug', 'cardapio')->where('name', 'Cardápio Digital')->update(['name' => 'Básico']);
        DB::table('plans')->where('slug', 'site-cardapio')->where('name', 'Site Profissional + Cardápio')->update(['name' => 'Site profissional']);

        $plans = DB::table('plans')->get(['id', 'prices_json'])->keyBy('id');
        foreach (DB::table('subscriptions')->whereIn('status', ['trialing', 'active', 'past_due'])->get(['id', 'plan_id', 'billing_months', 'price']) as $sub) {
            $update = ['modules_json' => json_encode(ModuleKey::values())];
            if ($sub->price === null) {
                $prices = json_decode((string) ($plans[$sub->plan_id]->prices_json ?? 'null'), true) ?: [];
                $current = $prices[(string) $sub->billing_months] ?? null;
                // Sem preço hoje = não é cobrado hoje: fica sem cobrança (0,00)
                // até a SerTec definir o valor, para ninguém ser cobrado de surpresa.
                $update['price'] = $current !== null && $current !== '' ? number_format((float) $current, 2, '.', '') : '0.00';
            }
            DB::table('subscriptions')->where('id', $sub->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('lines_json'));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('modules_json'));
        Schema::dropIfExists('billing_modules');
    }
};

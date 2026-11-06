<?php

namespace App\Modules\Platform\Modules;

use App\Modules\Platform\Features\Feature;

/**
 * Módulos vendidos separadamente (D100). Cada um libera uma funcionalidade
 * no FeatureGate; o preço mensal/anual fica em billing_modules.
 */
enum ModuleKey: string
{
    /** Cardápio digital: loja online, carrinho, pedidos pelo site. */
    case Menu = 'menu';

    /** PDV e caixa: venda de balcão, caixa e financeiro. */
    case Pos = 'pos';

    /** Mesas e comandas (fecha no caixa: exige o PDV). */
    case Tabs = 'tabs';

    /** Estoque simples. */
    case Stock = 'stock';

    public function label(): string
    {
        return match ($this) {
            self::Menu => 'Cardápio digital',
            self::Pos => 'PDV e caixa',
            self::Tabs => 'Mesas e comandas',
            self::Stock => 'Estoque',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Menu => 'Loja online com carrinho, pedidos pelo site, entrega/retirada e pagamento online.',
            self::Pos => 'Venda no balcão, caixa com abertura e fechamento, taxas, custos fixos e resultado.',
            self::Tabs => 'Contas abertas por mesa, envio para a cozinha e fechamento com taxa de serviço.',
            self::Stock => 'Saldo por produto e unidade, baixa automática na venda e esgotado sozinho.',
        };
    }

    /** Funcionalidade que o módulo libera. */
    public function feature(): string
    {
        return match ($this) {
            self::Menu => Feature::ONLINE_ORDERING,
            self::Pos => Feature::CASHBOOK,
            self::Tabs => Feature::TABS,
            self::Stock => Feature::STOCK,
        };
    }

    /** Módulo de que este depende (null = nenhum). */
    public function requires(): ?self
    {
        return $this === self::Tabs ? self::Pos : null;
    }

    /**
     * Funcionalidades que só os módulos liberam (o plano não liga).
     *
     * @return list<string>
     */
    public static function features(): array
    {
        return array_map(fn (self $m) => $m->feature(), self::cases());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    /**
     * Normaliza uma lista vinda do banco/requisição (ordem fixa, sem repetidos).
     *
     * @param  list<string>|null  $keys
     * @return list<self>
     */
    public static function list(?array $keys): array
    {
        $set = array_flip(array_map('strval', $keys ?? []));

        return array_values(array_filter(self::cases(), fn (self $m) => isset($set[$m->value])));
    }
}

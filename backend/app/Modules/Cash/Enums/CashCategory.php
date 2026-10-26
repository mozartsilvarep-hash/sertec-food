<?php

namespace App\Modules\Cash\Enums;

/** Categorias fixas: relatório consistente sem cadastro extra. */
enum CashCategory: string
{
    // Entradas
    case CounterSale = 'counter_sale';
    case Order = 'order';
    case Supply = 'supply';
    case OtherIn = 'other_in';
    // Saídas
    case Withdrawal = 'withdrawal';
    case Supplier = 'supplier';
    case Staff = 'staff';
    case Bills = 'bills';
    case Refund = 'refund';
    case OtherOut = 'other_out';

    public function label(): string
    {
        return match ($this) {
            self::CounterSale => 'Venda no balcão',
            self::Order => 'Pedido do cardápio',
            self::Supply => 'Reforço de caixa',
            self::OtherIn => 'Outra entrada',
            self::Withdrawal => 'Retirada (sangria)',
            self::Supplier => 'Fornecedores e insumos',
            self::Staff => 'Funcionários',
            self::Bills => 'Contas (água, luz, aluguel…)',
            self::Refund => 'Estorno de pedido',
            self::OtherOut => 'Outra saída',
        };
    }

    public function isIn(): bool
    {
        return in_array($this, [self::CounterSale, self::Order, self::Supply, self::OtherIn], true);
    }

    /** Lançadas à mão pela tela (as outras vêm de venda/pedido/estorno). */
    public function isManual(): bool
    {
        return ! in_array($this, [self::CounterSale, self::Order, self::Refund], true);
    }

    /** Reforço e sangria são sempre em dinheiro. */
    public function isDrawerOnly(): bool
    {
        return in_array($this, [self::Supply, self::Withdrawal], true);
    }
}

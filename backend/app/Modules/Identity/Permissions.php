<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Models\Role;

/**
 * Catálogo de permissões do painel do restaurante e papéis globais padrão.
 * Fonte única para seeders e testes; novas sprints acrescentam chaves aqui.
 */
final class Permissions
{
    public const BRANCHES_VIEW = 'branches.view';

    public const BRANCHES_MANAGE = 'branches.manage';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_UPDATE = 'settings.update';

    public const STAFF_MANAGE = 'staff.manage';

    public const CATALOG_VIEW = 'catalog.view';

    public const CATALOG_MANAGE = 'catalog.manage';

    /** Marcar produto como esgotado/disponível na própria unidade. */
    public const CATALOG_AVAILABILITY = 'catalog.availability';

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_MANAGE = 'orders.manage';

    public const REPORTS_VIEW = 'reports.view';

    /** Promoções, cupons e vitrine (feed/faixa). */
    public const MARKETING_MANAGE = 'marketing.manage';

    /** Ver a trilha de auditoria do estabelecimento (só o proprietário). */
    public const AUDIT_VIEW = 'audit.view';

    /** Conectar/desconectar a conta do Mercado Pago (só o proprietário). */
    public const PAYMENTS_MANAGE = 'payments.manage';

    /** Reembolsar pagamentos online. */
    public const PAYMENTS_REFUND = 'payments.refund';

    /** Caixa: abrir/fechar, venda de balcão e lançamentos do dia. */
    public const CASH_OPERATE = 'cash.operate';

    /** Caixa: estornar lançamentos, relatórios e exportação. */
    public const CASH_MANAGE = 'cash.manage';

    /** Ver e pagar a mensalidade da SerTec (só o proprietário). */
    public const BILLING_MANAGE = 'billing.manage';

    /**
     * @return array<string, string> chave => descrição
     */
    public static function all(): array
    {
        return [
            self::BRANCHES_VIEW => 'Ver unidades',
            self::BRANCHES_MANAGE => 'Gerenciar unidades',
            self::SETTINGS_VIEW => 'Ver configurações',
            self::SETTINGS_UPDATE => 'Alterar configurações',
            self::STAFF_MANAGE => 'Gerenciar funcionários e permissões',
            self::CATALOG_VIEW => 'Ver cardápio',
            self::CATALOG_MANAGE => 'Gerenciar cardápio',
            self::CATALOG_AVAILABILITY => 'Marcar produtos esgotados',
            self::ORDERS_VIEW => 'Ver pedidos',
            self::ORDERS_MANAGE => 'Operar pedidos',
            self::REPORTS_VIEW => 'Ver relatórios',
            self::MARKETING_MANAGE => 'Gerenciar promoções e cupons',
            self::AUDIT_VIEW => 'Ver auditoria',
            self::PAYMENTS_MANAGE => 'Gerenciar conta de pagamentos',
            self::PAYMENTS_REFUND => 'Reembolsar pagamentos',
            self::CASH_OPERATE => 'Operar o caixa',
            self::CASH_MANAGE => 'Gerenciar o caixa e relatórios do caixa',
            self::BILLING_MANAGE => 'Ver e pagar a mensalidade',
        ];
    }

    /**
     * Papéis globais (roles.tenant_id = NULL).
     *
     * @return array<string, array{name: string, permissions: list<string>}>
     */
    public static function defaultRoles(): array
    {
        $all = array_keys(self::all());

        return [
            Role::OWNER => ['name' => 'Proprietário', 'permissions' => $all],
            Role::MANAGER => ['name' => 'Gerente', 'permissions' => array_values(array_diff($all, [self::STAFF_MANAGE, self::PAYMENTS_MANAGE, self::AUDIT_VIEW, self::BILLING_MANAGE]))],
            Role::ATTENDANT => ['name' => 'Atendente', 'permissions' => [
                self::BRANCHES_VIEW, self::CATALOG_VIEW, self::CATALOG_AVAILABILITY, self::ORDERS_VIEW, self::ORDERS_MANAGE,
                self::CASH_OPERATE,
            ]],
            Role::KITCHEN => ['name' => 'Cozinha', 'permissions' => [
                self::CATALOG_AVAILABILITY, self::ORDERS_VIEW, self::ORDERS_MANAGE,
            ]],
        ];
    }
}

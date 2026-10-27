<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Enums\CashCategory;
use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Cash\Models\CashEntry;
use App\Modules\Cash\Models\FixedCost;
use App\Modules\Cash\Models\PaymentFee;
use App\Modules\Cash\Services\FinanceReport;
use App\Modules\Identity\Access\MemberAccess;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Financeiro (cash.manage): resultado do período, taxas das formas de
 * pagamento e custos fixos.
 */
class FinanceController
{
    private const MONEY = 'regex:/^\d{1,7}([.,]\d{1,2})?$/';

    /** Categorias de saída que um custo fixo pode ter. */
    private const FIXED_CATEGORIES = [CashCategory::Bills, CashCategory::Staff, CashCategory::Supplier, CashCategory::OtherOut];

    public function summary(Request $request, TenantAccess $tenantAccess, TenantContext $context, FinanceReport $report): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'string', 'size:26'],
        ], [], ['from' => 'início', 'to' => 'fim']);
        $tz = $context->get()->timezone;
        $from = CarbonImmutable::parse($data['from'], $tz)->startOfDay();
        $to = CarbonImmutable::parse($data['to'], $tz)->endOfDay();
        if ($from->diffInDays($to) > 370) {
            throw new ApiException(422, 'period_too_long', 'Escolha um período de até 1 ano.');
        }

        return new JsonResponse(['data' => $report->summary($from, $to, $this->branchIds($request, $tenantAccess, $data['branch'] ?? null), $tz)]);
    }

    public function fees(): JsonResponse
    {
        return new JsonResponse(['data' => $this->presentFees()]);
    }

    public function saveFees(Request $request, AuditLogger $audit): JsonResponse
    {
        $methods = array_map(fn (CashMethod $m) => $m->value, FinanceReport::feeMethods());
        $data = $request->validate([
            'fees' => ['required', 'array', 'max:10'],
            'fees.*.method' => ['required', Rule::in($methods), 'distinct'],
            'fees.*.percent' => ['required', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'fees.*.fixed' => ['nullable', 'regex:/^\d{1,4}([.,]\d{1,2})?$/'],
        ], ['fees.*.percent.regex' => 'Use um percentual como 2,99.', 'fees.*.fixed.regex' => 'Use um valor como 0,50.'], ['fees.*.percent' => 'percentual', 'fees.*.fixed' => 'valor fixo']);

        foreach ($data['fees'] as $row) {
            PaymentFee::query()->updateOrCreate(['method' => $row['method']], [
                'percent_bp' => (int) round((float) str_replace(',', '.', (string) $row['percent']) * 100),
                'fixed_amount' => Money::format(Money::toCents(str_replace(',', '.', (string) ($row['fixed'] ?? '0')))),
            ]);
        }
        $audit->record('finance.fees_updated', null, ['methods' => array_column($data['fees'], 'method')]);

        return new JsonResponse(['data' => $this->presentFees()]);
    }

    public function fixedCosts(Request $request, TenantAccess $tenantAccess): JsonResponse
    {
        $ids = $this->branchIds($request, $tenantAccess, null);
        $costs = FixedCost::query()->with('branch')
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $ids ?? [])))
            ->orderByDesc('active')->orderBy('name')->get();

        return new JsonResponse(['data' => $costs->map(fn (FixedCost $c) => $this->presentCost($c))->values()]);
    }

    public function storeFixedCost(Request $request, TenantAccess $tenantAccess, AuditLogger $audit): JsonResponse
    {
        $cost = FixedCost::query()->create($this->validatedCost($request, $tenantAccess, null));
        $audit->record('finance.fixed_cost_created', $cost, ['amount' => $cost->amount]);

        return new JsonResponse(['data' => $this->presentCost($cost->load('branch'))], 201);
    }

    public function updateFixedCost(Request $request, FixedCost $fixedCost, TenantAccess $tenantAccess, AuditLogger $audit): JsonResponse
    {
        $this->assertCostAccess($request, $tenantAccess, $fixedCost);
        $fixedCost->fill($this->validatedCost($request, $tenantAccess, $fixedCost))->save();
        $audit->record('finance.fixed_cost_updated', $fixedCost, ['amount' => $fixedCost->amount, 'active' => $fixedCost->active]);

        return new JsonResponse(['data' => $this->presentCost($fixedCost->load('branch'))]);
    }

    /** Apaga se nunca foi pago pelo caixa; senão, só desativa (histórico fica). */
    public function destroyFixedCost(Request $request, FixedCost $fixedCost, TenantAccess $tenantAccess, AuditLogger $audit): JsonResponse
    {
        $this->assertCostAccess($request, $tenantAccess, $fixedCost);
        if (CashEntry::query()->where('fixed_cost_id', $fixedCost->id)->exists()) {
            $fixedCost->forceFill(['active' => false])->save();
            $audit->record('finance.fixed_cost_deactivated', $fixedCost, []);

            return new JsonResponse(['data' => $this->presentCost($fixedCost->load('branch'))]);
        }
        $audit->record('finance.fixed_cost_deleted', $fixedCost, ['name' => $fixedCost->name]);
        $fixedCost->delete();

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCost(Request $request, TenantAccess $tenantAccess, ?FixedCost $cost): array
    {
        $data = $request->validate([
            'name' => [$cost ? 'sometimes' : 'required', 'string', 'min:2', 'max:80'],
            'category' => [$cost ? 'sometimes' : 'required', Rule::in(array_map(fn (CashCategory $c) => $c->value, self::FIXED_CATEGORIES))],
            'amount' => [$cost ? 'sometimes' : 'required', 'string', self::MONEY],
            'due_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'branch' => ['sometimes', 'nullable', 'string', 'size:26'],
            'active' => ['sometimes', 'boolean'],
        ], [], ['name' => 'nome', 'category' => 'tipo', 'amount' => 'valor por mês', 'due_day' => 'dia do vencimento']);

        $out = array_intersect_key($data, array_flip(['name', 'category', 'due_day', 'active']));
        if (isset($data['amount'])) {
            $cents = Money::toCents(str_replace(',', '.', (string) $data['amount']));
            if ($cents <= 0) {
                throw new ApiException(422, 'validation_failed', 'Informe o valor por mês.', ['fields' => ['amount' => ['Informe o valor por mês.']]]);
            }
            $out['amount'] = Money::format($cents);
        }
        if (array_key_exists('branch', $data)) {
            $out['branch_id'] = $data['branch'] === null ? null : $this->resolveBranch($request, $tenantAccess, $data['branch'])->id;
        }
        // Gerente de uma unidade só cria custo da própria unidade.
        $access = $this->access($request, $tenantAccess);
        if ($access->branchIds !== null && ! array_key_exists('branch_id', $out) && $cost === null) {
            $out['branch_id'] = $access->branchIds[0] ?? throw ApiException::forbidden();
        }
        if ($access->branchIds !== null && array_key_exists('branch_id', $out) && $out['branch_id'] === null) {
            throw ApiException::forbidden();
        }

        return $out;
    }

    private function assertCostAccess(Request $request, TenantAccess $tenantAccess, FixedCost $cost): void
    {
        $access = $this->access($request, $tenantAccess);
        if ($access->branchIds !== null && ($cost->branch_id === null || ! $access->canAccessBranch($cost->branch_id))) {
            throw ApiException::forbidden();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentFees(): array
    {
        $saved = PaymentFee::query()->get()->keyBy(fn (PaymentFee $f) => $f->method->value);

        return array_map(fn (CashMethod $m) => [
            'method' => $m->value,
            'label' => $m->label(),
            'percent' => number_format(($saved[$m->value]->percent_bp ?? 0) / 100, 2, '.', ''),
            'fixed' => $saved[$m->value]->fixed_amount ?? '0.00',
        ], FinanceReport::feeMethods());
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCost(FixedCost $c): array
    {
        return [
            'id' => $c->public_id,
            'name' => $c->name,
            'category' => $c->category->value,
            'category_label' => $c->category->label(),
            'amount' => $c->amount,
            'due_day' => $c->due_day,
            'active' => $c->active,
            'branch' => $c->branch === null ? null : ['id' => $c->branch->public_id, 'name' => $c->branch->name],
        ];
    }

    /**
     * @return list<int>|null
     */
    private function branchIds(Request $request, TenantAccess $tenantAccess, ?string $branch): ?array
    {
        $access = $this->access($request, $tenantAccess);
        if ($branch !== null && $branch !== '') {
            return [$this->resolveBranch($request, $tenantAccess, $branch)->id];
        }

        return $access->branchIds;
    }

    private function resolveBranch(Request $request, TenantAccess $tenantAccess, string $id): Branch
    {
        $branch = PublicIds::one(Branch::class, $id, 'branch');
        if (! $this->access($request, $tenantAccess)->canAccessBranch($branch->id)) {
            throw ApiException::forbidden();
        }

        return $branch;
    }

    private function access(Request $request, TenantAccess $tenantAccess): MemberAccess
    {
        $user = $request->user();

        return $tenantAccess->for($user instanceof User ? $user : throw ApiException::forbidden());
    }
}

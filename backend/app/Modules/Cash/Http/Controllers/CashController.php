<?php

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Cash\Enums\CashCategory;
use App\Modules\Cash\Enums\CashMethod;
use App\Modules\Cash\Models\CashEntry;
use App\Modules\Cash\Models\CashSession;
use App\Modules\Cash\Services\CashBook;
use App\Modules\Identity\Access\MemberAccess;
use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Permissions as P;
use App\Modules\Platform\Audit\AuditLogger;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Support\PublicIds;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Caixa (mini PDV) da unidade. cash.operate: abrir/fechar, vender e lançar
 * no caixa aberto. cash.manage: estornar, relatórios, histórico e CSV.
 * Atendente presa a uma unidade só opera aquela unidade.
 */
class CashController
{
    private const MONEY = 'regex:/^\d{1,7}([.,]\d{1,2})?$/';

    public function __construct(private readonly CashBook $book) {}

    /** GET cash/session?branch= — caixa aberto (ou null) com os totais até agora. */
    public function current(Request $request, TenantAccess $access): JsonResponse
    {
        $branch = $this->branch($request, $access);
        $session = $this->book->current($branch);

        return new JsonResponse(['data' => $session === null ? null : $this->presentSession($session, withEntries: true)]);
    }

    public function open(Request $request, TenantAccess $access, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['opening_amount' => ['required', 'string', self::MONEY]], [], ['opening_amount' => 'troco inicial']);
        $branch = $this->branch($request, $access);
        $session = $this->book->open($branch, self::cents($data['opening_amount']), $this->user($request));
        $audit->record('cash.opened', $session, ['opening' => $session->opening_amount]);

        return new JsonResponse(['data' => $this->presentSession($session, withEntries: true)], 201);
    }

    public function close(Request $request, CashSession $session, TenantAccess $access, AuditLogger $audit): JsonResponse
    {
        $this->assertBranch($request, $access, $session->branch_id);
        $data = $request->validate([
            'counted_amount' => ['required', 'string', self::MONEY],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [], ['counted_amount' => 'dinheiro contado']);
        $closed = $this->book->close($session, self::cents($data['counted_amount']), $data['notes'] ?? null, $this->user($request));
        $audit->record('cash.closed', $closed, ['expected' => $closed->expected_amount, 'counted' => $closed->counted_amount]);

        return new JsonResponse(['data' => $this->presentSession($closed, withEntries: true)]);
    }

    /** Lançamento manual: reforço, sangria, despesa ou outra entrada/saída. */
    public function entry(Request $request, TenantAccess $access): JsonResponse
    {
        $manual = array_map(fn (CashCategory $c) => $c->value, array_filter(CashCategory::cases(), fn (CashCategory $c) => $c->isManual()));
        $data = $request->validate([
            'category' => ['required', Rule::in($manual)],
            'method' => ['required', Rule::enum(CashMethod::class)],
            'amount' => ['required', 'string', self::MONEY],
            'description' => ['nullable', 'string', 'max:200'],
        ], [], ['amount' => 'valor', 'category' => 'tipo', 'method' => 'forma de pagamento', 'description' => 'descrição']);
        $branch = $this->branch($request, $access);
        $this->requireOpen($branch);
        $category = CashCategory::from($data['category']);
        $method = $category->isDrawerOnly() ? CashMethod::Cash : CashMethod::from($data['method']);
        $amount = self::cents($data['amount']);
        if ($amount <= 0) {
            throw new ApiException(422, 'cash_amount_invalid', 'Informe um valor maior que zero.');
        }
        $entry = $this->book->record($branch, $category->isIn() ? CashEntry::IN : CashEntry::OUT, $category, $method, $amount, $data['description'] ?? null, $this->user($request));

        return new JsonResponse(['data' => $this->presentEntry($entry)], 201);
    }

    /** Venda de balcão (mini PDV). */
    public function sale(Request $request, TenantAccess $access): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.product_id' => ['nullable', 'string', 'size:26'],
            'items.*.variant_id' => ['nullable', 'string', 'size:26'],
            'items.*.description' => ['required_without:items.*.product_id', 'nullable', 'string', 'max:80'],
            'items.*.unit_price' => ['required_without:items.*.product_id', 'nullable', 'string', self::MONEY],
            'items.*.quantity' => ['required', 'integer', 'between:1,999'],
            'discount' => ['nullable', 'string', self::MONEY],
            'method' => ['required', Rule::enum(CashMethod::class), Rule::notIn([CashMethod::Online->value])],
            'received' => ['nullable', 'string', self::MONEY],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['items' => 'itens', 'method' => 'forma de pagamento', 'items.*.description' => 'descrição', 'items.*.unit_price' => 'valor']);
        $branch = $this->branch($request, $access);
        $this->requireOpen($branch);
        $method = CashMethod::from($data['method']);
        $entry = $this->book->sale($branch, $data['items'], isset($data['discount']) ? self::cents($data['discount']) : 0, $method, $this->user($request), $data['note'] ?? null);

        $change = null;
        if ($method === CashMethod::Cash && ! empty($data['received'])) {
            $received = self::cents($data['received']);
            if ($received < Money::toCents((string) $entry->amount)) {
                $entry->delete();
                throw new ApiException(422, 'sale_received_short', 'O valor recebido é menor que o total.');
            }
            $change = Money::format($received - Money::toCents((string) $entry->amount));
        }

        return new JsonResponse(['data' => [...$this->presentEntry($entry), 'change' => $change]], 201);
    }

    public function void(Request $request, CashEntry $entry, TenantAccess $access, AuditLogger $audit): JsonResponse
    {
        $this->assertBranch($request, $access, $entry->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], [], ['reason' => 'motivo']);
        $this->book->void($entry, $data['reason'], $this->user($request));
        $audit->record('cash.entry_voided', $entry, ['amount' => $entry->amount, 'reason' => $data['reason']]);

        return new JsonResponse(['data' => $this->presentEntry($entry->refresh())]);
    }

    /** GET cash/entries — período (cash.manage) ou caixa atual (cash.operate). */
    public function entries(Request $request, TenantAccess $tenantAccess, TenantContext $context): JsonResponse
    {
        $access = $tenantAccess->for($this->user($request));
        $query = $this->filtered($request, $access, $context->get()->timezone)->with(['creator', 'branch', 'order'])
            ->orderByDesc('occurred_at')->orderByDesc('id');

        return new JsonResponse(['data' => $query->limit(500)->get()->map(fn (CashEntry $e) => $this->presentEntry($e))->values()]);
    }

    /** GET cash/summary — totais do período por forma, categoria e dia. */
    public function summary(Request $request, TenantAccess $tenantAccess, TenantContext $context): JsonResponse
    {
        $access = $tenantAccess->for($this->user($request));
        $timezone = $context->get()->timezone;
        $rows = $this->filtered($request, $access, $timezone)->valid()
            ->get(['type', 'category', 'method', 'amount', 'occurred_at']);

        $in = $out = 0;
        $methods = $categories = $days = [];
        foreach ($rows as $r) {
            $cents = Money::toCents((string) $r->amount);
            $isIn = $r->type === CashEntry::IN;
            $isIn ? $in += $cents : $out += $cents;
            $m = $r->method->value;
            $methods[$m] ??= ['method' => $m, 'label' => $r->method->label(), 'in' => 0, 'out' => 0];
            $methods[$m][$isIn ? 'in' : 'out'] += $cents;
            $c = $r->category->value;
            $categories[$c] ??= ['category' => $c, 'label' => $r->category->label(), 'type' => $r->type, 'count' => 0, 'total' => 0];
            $categories[$c]['count']++;
            $categories[$c]['total'] += $cents;
            $d = $r->occurred_at->setTimezone($timezone)->format('Y-m-d');
            $days[$d] ??= ['date' => $d, 'in' => 0, 'out' => 0];
            $days[$d][$isIn ? 'in' : 'out'] += $cents;
        }
        ksort($days);
        $fmt = fn (array $list, array $keys) => array_values(array_map(function ($row) use ($keys) {
            foreach ($keys as $k) {
                $row[$k] = Money::format($row[$k]);
            }

            return $row;
        }, $list));
        usort($categories, fn ($a, $b) => $b['total'] <=> $a['total']);

        return new JsonResponse(['data' => [
            'in' => Money::format($in),
            'out' => Money::format($out),
            'balance' => Money::format($in - $out),
            'by_method' => $fmt($methods, ['in', 'out']),
            'by_category' => $fmt($categories, ['total']),
            'by_day' => $fmt($days, ['in', 'out']),
        ]]);
    }

    /** GET cash/sessions — caixas fechados (conferência). */
    public function sessions(Request $request, TenantAccess $tenantAccess): JsonResponse
    {
        $access = $tenantAccess->for($this->user($request));
        $sessions = CashSession::query()->with(['branch', 'opener', 'closer'])
            ->when($access->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $access->branchIds ?? []))
            ->when($request->filled('branch'), fn ($q) => $q->where('branch_id', $this->branch($request, $tenantAccess)->id))
            ->orderByDesc('opened_at')->limit(60)->get();

        return new JsonResponse(['data' => $sessions->map(fn (CashSession $s) => $this->presentSession($s))->values()]);
    }

    public function csv(Request $request, TenantAccess $tenantAccess, TenantContext $context, AuditLogger $audit): StreamedResponse
    {
        $access = $tenantAccess->for($this->user($request));
        $tenant = $context->get();
        $timezone = $tenant->timezone;
        $query = $this->filtered($request, $access, $timezone)->with(['branch', 'creator'])->orderBy('occurred_at')->orderBy('id');
        $audit->record('cash.exported', null, ['from' => $request->query('from'), 'to' => $request->query('to')]);
        $name = 'caixa-'.($request->query('from') ?: 'inicio').'-a-'.($request->query('to') ?: 'hoje').'.csv';

        return new StreamedResponse(fn () => app(TenantContext::class)->run($tenant, function () use ($query, $timezone) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Data', 'Unidade', 'Tipo', 'Categoria', 'Forma', 'Valor', 'Descrição', 'Lançado por', 'Estornado'], ';');
            $safe = fn (?string $v) => $v !== null && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
            foreach ($query->cursor() as $e) {
                /** @var CashEntry $e */
                fputcsv($out, [
                    $e->occurred_at->setTimezone($timezone)->format('d/m/Y H:i'),
                    $safe($e->branch->name),
                    $e->type === CashEntry::IN ? 'Entrada' : 'Saída',
                    $e->category->label(),
                    $e->method->label(),
                    str_replace('.', ',', (string) $e->amount),
                    $safe($e->description),
                    $safe($e->creator?->name),
                    $e->voided_at ? 'sim: '.$safe($e->void_reason) : '',
                ], ';');
            }
            fclose($out);
        }), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /* ---------- apoio ---------- */

    /**
     * Operador sem cash.manage só vê o caixa aberto da unidade.
     *
     * @return Builder<CashEntry>
     */
    private function filtered(Request $request, MemberAccess $access, string $timezone): Builder
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'string', 'size:26'],
            'session' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', 'in:in,out'],
            'category' => ['nullable', Rule::enum(CashCategory::class)],
        ]);
        $query = CashEntry::query()
            ->when($access->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $access->branchIds ?? []));
        if (! empty($data['branch'])) {
            $query->where('branch_id', PublicIds::one(Branch::class, $data['branch'], 'branch')->id);
        }
        if (! $access->can(P::CASH_MANAGE)) {
            $branch = $this->branch($request, app(TenantAccess::class));
            $session = $this->book->current($branch);

            return $query->where('cash_session_id', $session === null ? 0 : $session->id);
        }
        if (! empty($data['session'])) {
            $query->where('cash_session_id', PublicIds::one(CashSession::class, $data['session'], 'session')->id);
        }
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], $timezone)->startOfDay() : null;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], $timezone)->endOfDay() : null;
        if ($from !== null && $to !== null && $from->diffInDays($to) > 370) {
            throw new ApiException(422, 'period_too_long', 'Escolha um período de até 1 ano.');
        }

        return $query->when($from, fn ($q) => $q->where('occurred_at', '>=', $from->utc()))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to->utc()))
            ->when(! empty($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->when(! empty($data['category']), fn ($q) => $q->where('category', $data['category']));
    }

    private function branch(Request $request, TenantAccess $tenantAccess): Branch
    {
        $id = (string) $request->input('branch', $request->query('branch', ''));
        $access = $tenantAccess->for($this->user($request));
        if ($id === '') {
            // Sem unidade informada: a única unidade do usuário (ou do restaurante).
            $branches = Branch::query()->where('active', true)
                ->when($access->branchIds !== null, fn ($q) => $q->whereIn('id', $access->branchIds ?? []))->limit(2)->get();
            if ($branches->count() !== 1) {
                throw new ApiException(422, 'branch_required', 'Escolha a unidade.');
            }

            return $branches->first();
        }
        $branch = PublicIds::one(Branch::class, $id, 'branch');
        $this->assertBranch($request, $tenantAccess, $branch->id);

        return $branch;
    }

    private function assertBranch(Request $request, TenantAccess $tenantAccess, int $branchId): void
    {
        if (! $tenantAccess->for($this->user($request))->canAccessBranch($branchId)) {
            throw ApiException::forbidden();
        }
    }

    private function requireOpen(Branch $branch): CashSession
    {
        return $this->book->current($branch)
            ?? throw new ApiException(409, 'cash_closed', 'Abra o caixa desta unidade antes de lançar.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw ApiException::forbidden();
    }

    private static function cents(string $value): int
    {
        return Money::toCents(number_format((float) str_replace(',', '.', $value), 2, '.', ''));
    }

    /** @return array<string, mixed> */
    private function presentSession(CashSession $s, bool $withEntries = false): array
    {
        $expected = $s->isOpen() ? $this->book->expectedCents($s) : Money::toCents((string) $s->expected_amount);
        $base = [
            'id' => $s->public_id,
            'branch' => ['id' => $s->branch->public_id, 'name' => $s->branch->name],
            'opened_at' => $s->opened_at->toIso8601String(),
            'opened_by' => $s->opener?->name,
            'opening_amount' => $s->opening_amount,
            'open' => $s->isOpen(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'closed_by' => $s->closer?->name,
            'expected_amount' => Money::format($expected),
            'counted_amount' => $s->counted_amount,
            'difference' => $s->counted_amount === null ? null : Money::format(Money::toCents((string) $s->counted_amount) - $expected),
            'notes' => $s->notes,
        ];
        if (! $withEntries) {
            return $base;
        }
        $rows = CashEntry::query()->valid()->where('cash_session_id', $s->id)
            ->selectRaw('type, method, SUM(amount) AS s, COUNT(*) AS c')->groupBy('type', 'method')->toBase()->get();
        $by = [];
        foreach ($rows as $r) {
            $method = CashMethod::from((string) $r->method);
            $by[$method->value] ??= ['method' => $method->value, 'label' => $method->label(), 'in' => 0, 'out' => 0];
            $by[$method->value][$r->type === CashEntry::IN ? 'in' : 'out'] += CashBook::cents($r->s);
        }

        return [...$base, 'by_method' => array_values(array_map(fn ($r) => [...$r, 'in' => Money::format($r['in']), 'out' => Money::format($r['out'])], $by))];
    }

    /** @return array<string, mixed> */
    private function presentEntry(CashEntry $e): array
    {
        return [
            'id' => $e->public_id,
            'type' => $e->type,
            'category' => $e->category->value,
            'category_label' => $e->category->label(),
            'method' => $e->method->value,
            'method_label' => $e->method->label(),
            'amount' => $e->amount,
            'description' => $e->description,
            'items' => $e->items_json,
            'order_number' => $e->order?->order_number,
            'occurred_at' => $e->occurred_at->toIso8601String(),
            'created_by' => $e->creator?->name,
            'voided' => $e->voided_at !== null,
            'void_reason' => $e->void_reason,
        ];
    }
}

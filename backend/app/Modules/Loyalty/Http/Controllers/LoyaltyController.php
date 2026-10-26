<?php

namespace App\Modules\Loyalty\Http\Controllers;

use App\Http\Errors\ApiException;
use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Models\LoyaltyCard;
use App\Modules\Loyalty\Models\LoyaltyLedger;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Ordering\Support\Phone;
use App\Modules\Platform\Audit\AuditLogger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Painel → Promoções → Fidelidade (marketing.manage, recurso "loyalty").
 * GET/PUT  merchant/loyalty            regra do cartão
 * GET      merchant/loyalty/card?phone= cartão de um cliente + histórico
 * POST     merchant/loyalty/card/adjust {phone, stamps, note}
 */
class LoyaltyController
{
    public function show(): JsonResponse
    {
        return new JsonResponse(['data' => self::present(LoyaltyProgram::query()->first())]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'stamps_required' => ['required', 'integer', 'between:2,50'],
            'min_order' => ['nullable', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
            'reward_type' => ['required', Rule::in([LoyaltyProgram::FIXED, LoyaltyProgram::PERCENT])],
            'reward_amount' => ['nullable', 'required_if:reward_type,fixed', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
            'reward_percent' => ['nullable', 'required_if:reward_type,percent', 'string', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'reward_max' => ['nullable', 'string', 'regex:/^\d{1,6}([.,]\d{1,2})?$/'],
        ], [], [
            'stamps_required' => 'selos para o prêmio', 'min_order' => 'pedido mínimo', 'reward_amount' => 'valor do prêmio',
            'reward_percent' => 'percentual do prêmio', 'reward_max' => 'desconto máximo',
        ]);
        $money = fn (?string $v) => $v === null || $v === '' ? null : Money::format(Money::toCents(number_format((float) str_replace(',', '.', $v), 2, '.', '')));
        $program = LoyaltyProgram::query()->first() ?? new LoyaltyProgram;
        $fixed = $data['reward_type'] === LoyaltyProgram::FIXED;
        $percentBp = $fixed ? null : (int) round((float) str_replace(',', '.', (string) $data['reward_percent']) * 100);
        if (! $fixed && ($percentBp < 1 || $percentBp > 10000)) {
            throw ValidationException::withMessages(['reward_percent' => 'O percentual vai de 0,01% a 100%.']);
        }
        if ($fixed && Money::toCents((string) $money($data['reward_amount'])) <= 0) {
            throw ValidationException::withMessages(['reward_amount' => 'Informe um valor maior que zero.']);
        }
        $program->fill([
            'active' => $data['active'],
            'stamps_required' => $data['stamps_required'],
            'min_order' => $money($data['min_order'] ?? null),
            'reward_type' => $data['reward_type'],
            'reward_amount' => $fixed ? $money($data['reward_amount']) : null,
            'reward_percent_bp' => $percentBp,
            'reward_max' => $fixed ? null : $money($data['reward_max'] ?? null),
        ])->save();
        $audit->record('loyalty.program_updated', null, ['active' => $program->active, 'stamps' => $program->stamps_required, 'reward' => $program->rewardLabel()]);

        return new JsonResponse(['data' => self::present($program)]);
    }

    public function card(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $card = LoyaltyCard::query()->where('phone', $phone)->first();
        $program = LoyaltyProgram::query()->first();

        return new JsonResponse(['data' => [
            'phone' => Phone::format($phone),
            'stamps' => $card->stamps ?? 0,
            'rewards' => $card->rewards ?? 0,
            'required' => $program->stamps_required ?? null,
            'history' => $card === null ? [] : $card->ledger()->with(['order', 'actor'])->orderByDesc('id')->limit(50)->get()
                ->map(fn (LoyaltyLedger $l) => [
                    'type' => $l->type,
                    'stamps' => $l->stamps_delta,
                    'rewards' => $l->rewards_delta,
                    'amount' => $l->amount,
                    'order_number' => $l->order?->order_number,
                    'note' => $l->note,
                    'by' => $l->actor?->name,
                    'at' => $l->created_at->toIso8601String(),
                ])->values(),
        ]]);
    }

    public function adjust(Request $request, LoyaltyService $loyalty, AuditLogger $audit): JsonResponse
    {
        $phone = $this->phone($request);
        $data = $request->validate([
            'stamps' => ['required', 'integer', 'between:-50,50', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['stamps' => 'selos', 'note' => 'motivo']);
        $user = $request->user();
        if (! $user instanceof User) {
            throw ApiException::forbidden();
        }
        $card = $loyalty->adjust($phone, (int) $data['stamps'], $data['note'] ?? null, $user);
        $audit->record('loyalty.adjusted', null, ['stamps' => $data['stamps'], 'phone_hash' => hash('sha256', $phone)]);

        return new JsonResponse(['data' => ['stamps' => $card->stamps, 'rewards' => $card->rewards]]);
    }

    private function phone(Request $request): string
    {
        $raw = (string) $request->input('phone', $request->query('phone', ''));
        $phone = Phone::normalize($raw);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Informe um celular com DDD, ex.: (79) 99999-0000.']);
        }

        return $phone;
    }

    /** @return array<string, mixed> */
    private static function present(?LoyaltyProgram $p): array
    {
        return [
            'active' => $p->active ?? false,
            'stamps_required' => $p->stamps_required ?? 10,
            'min_order' => $p?->min_order,
            'reward_type' => $p->reward_type ?? LoyaltyProgram::FIXED,
            'reward_amount' => $p?->reward_amount,
            'reward_percent' => $p?->reward_percent_bp === null ? null : rtrim(rtrim(number_format($p->reward_percent_bp / 100, 2, '.', ''), '0'), '.'),
            'reward_max' => $p?->reward_max,
            'reward_label' => $p?->rewardLabel(),
        ];
    }
}

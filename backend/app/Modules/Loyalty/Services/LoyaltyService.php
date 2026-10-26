<?php

namespace App\Modules\Loyalty\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Loyalty\Models\LoyaltyCard;
use App\Modules\Loyalty\Models\LoyaltyLedger;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Services\OrderingException;
use App\Modules\Platform\Features\Feature;
use App\Modules\Platform\Features\FeatureGate;
use App\Support\Money;

/**
 * Cartão fidelidade por celular (dentro do restaurante atual).
 * - Selo: quando o pedido é CONCLUÍDO e os itens (sem a entrega) atingem o
 *   mínimo. A cada N selos, vira 1 prêmio (desconto no próximo pedido).
 * - Prêmio: usado no checkout (travado com FOR UPDATE); se o pedido for
 *   cancelado/recusado, o prêmio volta.
 * - Tudo fica no histórico (loyalty_ledger), uma vez por pedido.
 */
final class LoyaltyService
{
    public function __construct(private readonly FeatureGate $features) {}

    public function program(): ?LoyaltyProgram
    {
        if (! $this->features->enabled(Feature::LOYALTY)) {
            return null;
        }
        $program = LoyaltyProgram::query()->first();

        return $program !== null && $program->active ? $program : null;
    }

    /** @return array{stamps: int, required: int, rewards: int, reward_label: string}|null */
    public function status(string $phone): ?array
    {
        $program = $this->program();
        if ($program === null || $phone === '') {
            return null;
        }
        $card = LoyaltyCard::query()->where('phone', $phone)->first();

        return [
            'stamps' => $card->stamps ?? 0,
            'required' => $program->stamps_required,
            'rewards' => $card->rewards ?? 0,
            'reward_label' => $program->rewardLabel(),
        ];
    }

    /**
     * Reserva um prêmio para o checkout (trava o cartão).
     *
     * @return array{card: LoyaltyCard, discount_cents: int, label: string}
     */
    public function reserve(string $phone, int $itemsCents): array
    {
        $program = $this->program()
            ?? throw OrderingException::make('loyalty_unavailable', 'O cartão fidelidade não está ativo.');
        /** @var LoyaltyCard|null $card */
        $card = LoyaltyCard::query()->where('phone', $phone)->lockForUpdate()->first();
        if ($card === null || $card->rewards < 1) {
            throw OrderingException::make('loyalty_no_reward', 'Este celular ainda não tem prêmio do cartão fidelidade.');
        }

        return ['card' => $card, 'discount_cents' => $program->rewardCents($itemsCents), 'label' => 'Cartão fidelidade ('.$program->rewardLabel().')'];
    }

    public function consume(LoyaltyCard $card, Order $order, int $discountCents): void
    {
        $card->decrement('rewards');
        LoyaltyLedger::query()->create([
            'loyalty_card_id' => $card->id, 'order_id' => $order->id, 'type' => LoyaltyLedger::REWARD_USED,
            'rewards_delta' => -1, 'amount' => Money::format($discountCents),
        ]);
    }

    /** Pedido cancelado/recusado: devolve o prêmio usado nele. */
    public static function restoreFor(Order $order): void
    {
        $used = LoyaltyLedger::query()->where('order_id', $order->id)->where('type', LoyaltyLedger::REWARD_USED)->first();
        if ($used === null || LoyaltyLedger::query()->where('order_id', $order->id)->where('type', LoyaltyLedger::REWARD_RESTORED)->exists()) {
            return;
        }
        LoyaltyCard::query()->whereKey($used->loyalty_card_id)->lockForUpdate()->first()?->increment('rewards');
        LoyaltyLedger::query()->create([
            'loyalty_card_id' => $used->loyalty_card_id, 'order_id' => $order->id, 'type' => LoyaltyLedger::REWARD_RESTORED,
            'rewards_delta' => 1, 'note' => 'Pedido cancelado',
        ]);
    }

    /** Pedido concluído: 1 selo se os itens atingirem o mínimo. */
    public function stampFor(Order $order): void
    {
        $program = $this->program();
        if ($program === null || strlen($order->customer_phone) < 10 || $order->anonymized_at !== null) {
            return;
        }
        $itemsCents = Money::toCents((string) $order->total) - Money::toCents((string) $order->delivery_fee);
        if ($itemsCents < max(1, $program->minOrderCents())) {
            return;
        }
        if (LoyaltyLedger::query()->where('order_id', $order->id)->where('type', LoyaltyLedger::STAMP)->exists()) {
            return;
        }
        $card = $this->lockedCard($order->customer_phone);
        $this->addStamps($card, $program, 1);
        LoyaltyLedger::query()->create([
            'loyalty_card_id' => $card->id, 'order_id' => $order->id, 'type' => LoyaltyLedger::STAMP, 'stamps_delta' => 1,
        ]);
    }

    /** Ajuste manual pelo restaurante (ex.: compra no balcão). */
    public function adjust(string $phone, int $stamps, ?string $note, User $actor): LoyaltyCard
    {
        $program = $this->program()
            ?? throw OrderingException::make('loyalty_unavailable', 'Ative o cartão fidelidade antes.');
        $card = $this->lockedCard($phone);
        if ($stamps < 0 && $card->stamps + $stamps < 0) {
            throw OrderingException::make('loyalty_adjust_invalid', 'O cartão não tem tantos selos para remover.');
        }
        $this->addStamps($card, $program, $stamps);
        LoyaltyLedger::query()->create([
            'loyalty_card_id' => $card->id, 'type' => LoyaltyLedger::ADJUST, 'stamps_delta' => $stamps,
            'note' => $note, 'actor_user_id' => $actor->id,
        ]);

        return $card->refresh();
    }

    private function lockedCard(string $phone): LoyaltyCard
    {
        LoyaltyCard::query()->firstOrCreate(['phone' => $phone]);

        return LoyaltyCard::query()->where('phone', $phone)->lockForUpdate()->firstOrFail();
    }

    /** Soma selos; a cada "stamps_required" completos vira 1 prêmio. */
    private function addStamps(LoyaltyCard $card, LoyaltyProgram $program, int $delta): void
    {
        $stamps = $card->stamps + $delta;
        $rewards = $card->rewards;
        while ($stamps >= $program->stamps_required) {
            $stamps -= $program->stamps_required;
            $rewards++;
        }
        $card->forceFill(['stamps' => max(0, $stamps), 'rewards' => $rewards])->save();
    }
}

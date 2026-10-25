<?php

namespace App\Modules\Payments\Enums;

/**
 * Estado de uma tentativa de pagamento online (tabela payments), derivado
 * SEMPRE do status consultado na API do provedor.
 */
enum PaymentAttemptStatus: string
{
    /** Aguardando o cliente pagar (QR gerado / checkout aberto). */
    case Pending = 'pending';

    /** Provedor analisando (in_process, in_mediation, authorized). */
    case InReview = 'in_review';

    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case ChargedBack = 'charged_back';

    /** Valor, moeda, referência ou conta não conferem: nada é liberado. */
    case Mismatch = 'mismatch';

    public static function fromMercadoPago(string $status): self
    {
        return match ($status) {
            'approved' => self::Approved,
            'authorized', 'in_process', 'in_mediation' => self::InReview,
            'rejected' => self::Rejected,
            'cancelled' => self::Cancelled,
            'refunded' => self::Refunded,
            'charged_back' => self::ChargedBack,
            default => self::Pending, // pending e status desconhecidos
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::InReview, self::Rejected], true);
    }

    public function wasPaid(): bool
    {
        return in_array($this, [self::Approved, self::Refunded, self::PartiallyRefunded, self::ChargedBack], true);
    }
}

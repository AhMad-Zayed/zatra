<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

enum PaymentStatus: string implements HasLabel, HasColor
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Unpaid => 'غير مدفوع',
            self::PartiallyPaid => 'مدفوع جزئياً',
            self::Paid => 'مدفوع',
            self::RefundPending => 'بانتظار الاسترداد',
            self::Refunded => 'تم الاسترداد',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::RefundPending => 'warning',
            self::Refunded => 'gray',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

enum PaymentStatus: string implements HasLabel, HasColor
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Unpaid => 'غير مدفوع',
            self::PartiallyPaid => 'مدفوع جزئياً',
            self::Paid => 'مدفوع',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
        };
    }
}

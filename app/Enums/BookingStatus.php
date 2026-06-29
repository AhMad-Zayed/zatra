<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

enum BookingStatus: string implements HasLabel, HasColor
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case ConfirmedPartial = 'confirmed_partial';
    case Cancelled = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Confirmed => 'مؤكد',
            self::ConfirmedPartial => 'مؤكد (عربون)',
            self::Cancelled => 'ملغي',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::ConfirmedPartial => 'info',
            self::Cancelled => 'danger',
        };
    }
}

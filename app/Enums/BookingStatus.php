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
        // Status-chip colors per the Azure Horizon spec: Confirmed=success, Pending=warning,
        // Cancelled=gray (terminal/inert, not an alarm — danger is reserved for states that
        // need action, which a cancelled booking no longer does).
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::ConfirmedPartial => 'info',
            self::Cancelled => 'gray',
        };
    }
}

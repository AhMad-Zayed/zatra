<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BusOwnershipTypeEnum: string implements HasLabel, HasColor
{
    case Owned = 'owned';
    case Rented = 'rented';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Owned => 'ملك الشركة',
            self::Rented => 'مستأجرة',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Owned => 'success',
            self::Rented => 'warning',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OccupancyTypeEnum: string implements HasLabel, HasColor
{
    case Shared = 'shared';
    case Single = 'single';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Shared => 'مشاركة',
            self::Single => 'فردي',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Shared => 'success',
            self::Single => 'warning',
        };
    }
}

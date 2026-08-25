<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Pure classification of a TripTemplate for filtering/reporting purposes only. Must never be
 * read by validation or business logic (passport requirements, hotel/package visibility, etc.)
 * — those are governed by RequirementPreset and PackageOption respectively, independently of
 * this field.
 */
enum TripTypeEnum: string implements HasLabel, HasColor
{
    case Domestic = 'domestic';
    case International = 'international';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Domestic => 'داخلي',
            self::International => 'خارجي',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Domestic => 'info',
            self::International => 'warning',
        };
    }
}

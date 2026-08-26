<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Shared by both driver_type and guide_type on TripBusAssignment — either role can be an
 * existing internal staff member (StaffResource-linked User) or an external person who isn't in
 * the system (e.g. a driver who comes with a rented bus, or a contracted external guide).
 */
enum AssignmentPersonTypeEnum: string implements HasLabel, HasColor
{
    case Internal = 'internal';
    case External = 'external';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Internal => 'موظف داخلي',
            self::External => 'خارجي',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Internal => 'primary',
            self::External => 'gray',
        };
    }
}

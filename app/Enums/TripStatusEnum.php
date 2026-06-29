<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TripStatusEnum: string implements HasLabel, HasColor
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'مسودة',
            self::Active => 'متاح للحجز',
            self::Closed => 'مغلق (مكتمل العدد/الوقت)',
            self::InProgress => 'قيد التنفيذ',
            self::Completed => 'مكتمل',
            self::Cancelled => 'ملغي',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Closed => 'warning',
            self::InProgress => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}

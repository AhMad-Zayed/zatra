<?php

namespace App\Enums;

enum WaitingListStatusEnum: string
{
    case Pending = 'pending';
    case Notified = 'notified';
    case Expired = 'expired';
    case Converted = 'converted';
}

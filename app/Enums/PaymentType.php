<?php

namespace App\Enums;

enum PaymentType: string
{
    case PAYMENT     = 'payment';
    case DEPOSIT    = 'deposit';
    case INSTALLMENT = 'installment';
    case FULL       = 'full';
    case REVERSAL   = 'reversal';
    case REFUND     = 'refund';
}

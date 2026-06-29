<?php

namespace App\Enums;

enum RefundScope: string
{
    case Full    = 'full';
    case Partial = 'partial';
}

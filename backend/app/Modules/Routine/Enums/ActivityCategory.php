<?php

namespace App\Modules\Routine\Enums;

enum ActivityCategory: string
{
    case Study = 'study';
    case Work = 'work';
    case Leisure = 'leisure';
    case Rest = 'rest';
    case Commitment = 'commitment';
    case Other = 'other';
}

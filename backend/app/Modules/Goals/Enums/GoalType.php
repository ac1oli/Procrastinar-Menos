<?php

namespace App\Modules\Goals\Enums;

enum GoalType: string
{
    case Personal = 'personal';
    case Professional = 'professional';
    case Learning = 'learning';
}

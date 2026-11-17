<?php

namespace App\Modules\Devices\Enums;

enum DevicePlatform: string
{
    case Windows = 'windows';
    case Android = 'android';
    case BrowserExtension = 'browser_extension';
}

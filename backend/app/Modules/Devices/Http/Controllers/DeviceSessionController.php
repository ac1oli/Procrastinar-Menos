<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Devices\Http\Resources\DeviceResource;
use Illuminate\Http\Request;

/** Endpoints chamados pelos próprios agentes, autenticados com token de dispositivo. */
class DeviceSessionController extends Controller
{
    /** Heartbeat: confirma a credencial e informa o estado de consentimento. */
    public function me(Request $request): DeviceResource
    {
        $device = $request->user();
        $device->forceFill(['last_seen_at' => now()])->save();

        return DeviceResource::make($device);
    }
}

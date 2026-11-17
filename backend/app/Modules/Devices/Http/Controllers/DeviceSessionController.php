<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Devices\Http\Resources\DeviceResource;
use Illuminate\Http\Request;

/** Endpoints chamados pelos próprios agentes, autenticados com token de dispositivo. */
class DeviceSessionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Heartbeat: confirma a credencial e informa consentimento e validade do token. */
    public function me(Request $request): DeviceResource
    {
        $device = $request->user();
        $device->forceFill(['last_seen_at' => now()])->save();

        return DeviceResource::make($device)->additional([
            'token_renewal_due' => $device->tokenRenewalDue(),
        ]);
    }

    /**
     * Renovação pelo próprio agente: devolve um token novo e invalida o atual.
     * O agente deve persistir o novo token antes de descartar o antigo.
     */
    public function rotateToken(Request $request): DeviceResource
    {
        $device = $request->user();

        $token = $device->issueToken();
        $this->audit->record('device.token_rotated', $device->user, $device);

        return DeviceResource::make($device)->additional($token->toArray());
    }
}

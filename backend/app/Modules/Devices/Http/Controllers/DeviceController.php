<?php

namespace App\Modules\Devices\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Devices\Http\Requests\StoreDeviceRequest;
use App\Modules\Devices\Http\Requests\UpdateDeviceRequest;
use App\Modules\Devices\Http\Resources\DeviceResource;
use App\Modules\Devices\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Gestão dos dispositivos do usuário autenticado (dashboard). */
class DeviceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $devices = Device::ownedBy($request->user())->latest()->get();

        return DeviceResource::collection($devices);
    }

    /** Vincula um dispositivo e devolve o token em claro uma única vez. */
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $user = $request->user();

        [$device, $token] = DB::transaction(function () use ($request, $user) {
            $device = $user->devices()->create($request->validated());

            return [$device, $device->issueToken()];
        });

        $this->audit->record('device.linked', $user, $device, ['platform' => $device->platform->value]);

        return DeviceResource::make($device)
            ->additional(['token' => $token])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Device $device): DeviceResource
    {
        Gate::authorize('view', $device);

        return DeviceResource::make($device);
    }

    public function update(UpdateDeviceRequest $request, Device $device): DeviceResource
    {
        Gate::authorize('update', $device);
        abort_if($device->isRevoked(), Response::HTTP_CONFLICT, 'Dispositivo revogado não pode ser alterado.');

        $device->fill($request->validated());
        $consentChanged = $device->isDirty('monitoring_enabled');
        $device->save();

        if ($consentChanged) {
            $this->audit->record(
                $device->monitoring_enabled ? 'device.monitoring_enabled' : 'device.monitoring_disabled',
                $request->user(),
                $device,
            );
        }

        return DeviceResource::make($device);
    }

    /** Revoga o dispositivo; o registro é mantido para o histórico. */
    public function destroy(Request $request, Device $device): Response
    {
        Gate::authorize('delete', $device);

        if (! $device->isRevoked()) {
            $device->revoke();
            $this->audit->record('device.revoked', $request->user(), $device);
        }

        return response()->noContent();
    }
}

<?php

namespace App\Modules\Devices\Models;

use App\Modules\Devices\Enums\DevicePlatform;
use App\Modules\Devices\Policies\DevicePolicy;
use App\Modules\Shared\Concerns\BelongsToUser;
use Database\Factories\DeviceFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Dispositivo vinculado a um usuário (agente Windows, app Android, extensão).
 *
 * É dono do próprio token Sanctum: requisições de agentes resolvem
 * $request->user() para o Device, nunca para o User.
 */
#[Fillable(['name', 'platform', 'monitoring_enabled'])]
#[UseFactory(DeviceFactory::class)]
#[UsePolicy(DevicePolicy::class)]
class Device extends Model implements AuthenticatableContract
{
    /** @use HasFactory<DeviceFactory> */
    use Authenticatable, BelongsToUser, HasApiTokens, HasFactory, HasUuids;

    /** Escopos mínimos concedidos ao token de um dispositivo. */
    public const TOKEN_ABILITIES = ['device:sync', 'rules:read', 'events:write'];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'monitoring_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Emite um novo token; o texto em claro só existe neste retorno. */
    public function issueToken(): string
    {
        return $this->createToken('device:'.$this->platform->value, self::TOKEN_ABILITIES)->plainTextToken;
    }

    public function revoke(): void
    {
        $this->tokens()->delete();
        $this->forceFill(['revoked_at' => now(), 'monitoring_enabled' => false])->save();
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}

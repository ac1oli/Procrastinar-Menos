<?php

namespace Tests\Feature\Devices;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Devices\Models\Device;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /** @return array{0: string, 1: string} [device id, token em claro] */
    private function linkDevice(string $platform = 'windows'): array
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/devices', ['name' => 'PC do quarto', 'platform' => $platform])
            ->assertCreated();

        return [$response->json('data.id'), $response->json('token')];
    }

    public function test_links_device_with_monitoring_disabled_by_default(): void
    {
        [$deviceId, $token] = $this->linkDevice();

        $device = Device::findOrFail($deviceId);
        $this->assertSame($this->user->id, $device->user_id);
        $this->assertFalse($device->monitoring_enabled);
        $this->assertNotEmpty($token);

        // Apenas o hash do token é persistido.
        $stored = DB::table('personal_access_tokens')->where('tokenable_id', $deviceId)->first();
        $this->assertSame(Device::class, $stored->tokenable_type);
        $this->assertStringNotContainsString(explode('|', $token, 2)[1], $stored->token);

        $this->assertTrue(AuditLog::where('action', 'device.linked')->where('subject_id', $deviceId)->exists());
    }

    public function test_token_is_not_exposed_after_creation(): void
    {
        [$deviceId] = $this->linkDevice();

        $this->getJson("/api/devices/{$deviceId}")->assertOk()->assertJsonMissingPath('token');
        $this->getJson('/api/devices')->assertOk()->assertJsonMissingPath('data.0.token');
    }

    public function test_lists_only_own_devices(): void
    {
        Device::factory()->for($this->user)->count(2)->create();
        Device::factory()->count(3)->create();

        Sanctum::actingAs($this->user);

        $this->getJson('/api/devices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_cannot_access_devices_of_another_user(): void
    {
        $foreign = Device::factory()->create();
        Sanctum::actingAs($this->user);

        $this->getJson("/api/devices/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/devices/{$foreign->id}", ['name' => 'invadido'])->assertNotFound();
        $this->deleteJson("/api/devices/{$foreign->id}")->assertNotFound();

        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_invalid_identifier_returns_not_found(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/devices/nao-e-um-uuid')->assertNotFound();
    }

    public function test_validates_platform(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/devices', ['name' => 'X', 'platform' => 'ios'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform');
    }

    public function test_device_token_authenticates_heartbeat(): void
    {
        [$deviceId, $token] = $this->linkDevice('android');

        $this->asDevice($token)->getJson('/api/device/me')
            ->assertOk()
            ->assertJsonPath('data.id', $deviceId)
            ->assertJsonPath('data.platform', 'android');

        $this->assertNotNull(Device::find($deviceId)->last_seen_at);
    }

    public function test_device_token_cannot_access_user_routes(): void
    {
        [, $token] = $this->linkDevice();

        $this->asDevice($token)->getJson('/api/me')->assertForbidden();
        $this->asDevice($token)->getJson('/api/devices')->assertForbidden();
        $this->asDevice($token)->getJson('/api/goals')->assertForbidden();
    }

    public function test_user_session_cannot_access_device_routes(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/device/me')->assertForbidden();
    }

    public function test_revocation_invalidates_token_and_keeps_history(): void
    {
        [$deviceId, $token] = $this->linkDevice();

        $this->deleteJson("/api/devices/{$deviceId}")->assertNoContent();

        $device = Device::findOrFail($deviceId);
        $this->assertNotNull($device->revoked_at);
        $this->assertFalse($device->monitoring_enabled);
        $this->assertSame(0, $device->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'device.revoked')->where('subject_id', $deviceId)->exists());

        $this->asDevice($token)->getJson('/api/device/me')->assertUnauthorized();
    }

    public function test_revoked_device_cannot_be_changed(): void
    {
        $device = Device::factory()->for($this->user)->revoked()->create();
        Sanctum::actingAs($this->user);

        $this->patchJson("/api/devices/{$device->id}", ['monitoring_enabled' => true])->assertConflict();
    }

    public function test_monitoring_consent_changes_are_audited(): void
    {
        [$deviceId] = $this->linkDevice();

        $this->patchJson("/api/devices/{$deviceId}", ['monitoring_enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.monitoring_enabled', true);

        $this->patchJson("/api/devices/{$deviceId}", ['monitoring_enabled' => false])->assertOk();

        $actions = AuditLog::where('subject_id', $deviceId)->orderBy('created_at')->pluck('action')->all();
        $this->assertContains('device.monitoring_enabled', $actions);
        $this->assertContains('device.monitoring_disabled', $actions);
    }
}

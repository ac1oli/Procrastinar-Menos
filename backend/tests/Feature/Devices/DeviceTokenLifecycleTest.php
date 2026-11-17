<?php

namespace Tests\Feature\Devices;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Devices\Models\Device;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class DeviceTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        config(['devices.token_ttl_days' => 90, 'devices.token_renewal_window_days' => 15]);
    }

    /** @return array{0: string, 1: string} */
    private function linkDevice(): array
    {
        Sanctum::actingAs($this->user);
        $response = $this->postJson('/api/devices', ['name' => 'PC', 'platform' => 'windows'])->assertCreated();

        return [$response->json('data.id'), $response->json('token')];
    }

    public function test_token_is_issued_with_expiration(): void
    {
        $this->freezeTime();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/devices', ['name' => 'PC', 'platform' => 'windows'])->assertCreated();

        $expected = now()->addDays(90)->toIso8601String();
        $response->assertJsonPath('token_expires_at', $expected)
            ->assertJsonPath('data.token_expires_at', $expected);
    }

    public function test_expired_token_is_rejected(): void
    {
        [, $token] = $this->linkDevice();

        $this->travel(91)->days();

        $this->asDevice($token)->getJson('/api/device/me')->assertUnauthorized();
    }

    public function test_heartbeat_signals_renewal_window(): void
    {
        [, $token] = $this->linkDevice();

        $this->asDevice($token)->getJson('/api/device/me')->assertJsonPath('token_renewal_due', false);

        $this->travel(80)->days();
        $this->asDevice($token)->getJson('/api/device/me')->assertJsonPath('token_renewal_due', true);
    }

    public function test_device_rotates_its_own_token(): void
    {
        [$deviceId, $old] = $this->linkDevice();
        $this->travel(80)->days();

        $response = $this->asDevice($old)->postJson('/api/device/token/rotate')->assertOk();
        $new = $response->json('token');

        $this->assertNotSame($old, $new);
        $this->assertSame(1, Device::find($deviceId)->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'device.token_rotated')->where('subject_id', $deviceId)->exists());

        $this->asDevice($old)->getJson('/api/device/me')->assertUnauthorized();
        $this->asDevice($new)->getJson('/api/device/me')->assertOk()->assertJsonPath('token_renewal_due', false);
    }

    public function test_user_reissues_token_and_old_one_stops_working(): void
    {
        [$deviceId, $old] = $this->linkDevice();

        $new = $this->postJson("/api/devices/{$deviceId}/token")->assertOk()->json('token');

        $this->assertNotEmpty($new);
        $this->assertTrue(AuditLog::where('action', 'device.token_reissued')->where('subject_id', $deviceId)->exists());
        $this->asDevice($old)->getJson('/api/device/me')->assertUnauthorized();
        $this->asDevice($new)->getJson('/api/device/me')->assertOk();
    }

    public function test_cannot_reissue_for_revoked_or_foreign_device(): void
    {
        $revoked = Device::factory()->for($this->user)->revoked()->create();
        $foreign = Device::factory()->create();
        Sanctum::actingAs($this->user);

        $this->postJson("/api/devices/{$revoked->id}/token")->assertConflict();
        $this->postJson("/api/devices/{$foreign->id}/token")->assertNotFound();
        $this->assertSame(0, $foreign->tokens()->count());
    }

    public function test_ttl_zero_disables_expiration(): void
    {
        config(['devices.token_ttl_days' => 0]);
        [, $token] = $this->linkDevice();

        $this->travel(400)->days();

        $this->asDevice($token)->getJson('/api/device/me')
            ->assertOk()
            ->assertJsonPath('data.token_expires_at', null)
            ->assertJsonPath('token_renewal_due', false);
    }

    public function test_revocation_clears_expiration(): void
    {
        [$deviceId] = $this->linkDevice();

        $this->deleteJson("/api/devices/{$deviceId}")->assertNoContent();

        $this->assertNull(Device::find($deviceId)->token_expires_at);
    }
}

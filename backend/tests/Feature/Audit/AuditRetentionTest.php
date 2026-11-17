<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function log(int $daysAgo): AuditLog
    {
        return AuditLog::create([
            'user_id' => User::factory()->create()->id,
            'action' => 'auth.login',
            'ip_address' => '192.168.0.10',
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_applies_ip_and_deletion_windows(): void
    {
        $recent = $this->log(10);
        $old = $this->log(120);
        $expired = $this->log(400);

        $this->artisan('audit:apply-retention')->assertSuccessful();

        $this->assertSame('192.168.0.10', $recent->fresh()->ip_address);
        $this->assertNull($old->fresh()->ip_address);
        $this->assertNotNull($old->fresh()->user_id);
        $this->assertModelMissing($expired);
    }
}

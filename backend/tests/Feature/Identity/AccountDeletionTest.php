<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Devices\Models\Device;
use App\Modules\Goals\Models\Goal;
use App\Modules\Routine\Models\RoutineBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Device $device;

    private string $deviceToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['password' => 'senhaSegura123']);
        $this->device = Device::factory()->for($this->user)->create();
        $this->deviceToken = $this->device->issueToken()->plainText;
        RoutineBlock::factory()->for($this->user)->create();
        Goal::factory()->for($this->user)->create();

        $base = ['user_id' => $this->user->id, 'ip_address' => '192.168.0.10', 'created_at' => now()];
        AuditLog::create($base + ['action' => 'auth.login']);
        AuditLog::create($base + ['action' => 'device.linked', 'subject_type' => 'Device',
            'subject_id' => $this->device->id, 'metadata' => ['platform' => 'windows']]);
        AuditLog::create($base + ['action' => 'auth.logout']); // não está na lista de retenção
    }

    private function deleteAccount(string $password): TestResponse
    {
        return $this->actingAs($this->user, 'web')->fromDashboard()
            ->deleteJson('/api/me', ['password' => $password]);
    }

    public function test_requires_current_password(): void
    {
        $this->deleteAccount('errada')->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertModelExists($this->user);
    }

    public function test_deletes_personal_data_and_credentials(): void
    {
        $this->deleteAccount('senhaSegura123')->assertNoContent();

        $this->assertModelMissing($this->user);
        $this->assertSame(0, Device::count());
        $this->assertSame(0, RoutineBlock::count());
        $this->assertSame(0, Goal::count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());

        $this->asDevice($this->deviceToken)->getJson('/api/device/me')->assertUnauthorized();
    }

    public function test_keeps_only_necessary_audit_records_anonymized(): void
    {
        $this->deleteAccount('senhaSegura123')->assertNoContent();

        $actions = AuditLog::orderBy('action')->pluck('action')->all();
        $this->assertSame(['account.deleted', 'auth.login', 'device.linked'], $actions);

        foreach (AuditLog::all() as $log) {
            $this->assertNull($log->user_id, $log->action);
            $this->assertNull($log->ip_address, $log->action);
            $this->assertNull($log->metadata, $log->action);
            $this->assertNull($log->subject_id, $log->action);
        }
    }

    public function test_does_not_touch_other_users(): void
    {
        $other = User::factory()->create();
        $otherLog = AuditLog::create(['user_id' => $other->id, 'action' => 'auth.logout',
            'ip_address' => '10.0.0.1', 'created_at' => now()]);
        $otherGoal = Goal::factory()->for($other)->create();

        $this->deleteAccount('senhaSegura123')->assertNoContent();

        $this->assertModelExists($other);
        $this->assertModelExists($otherGoal);
        $this->assertSame('10.0.0.1', $otherLog->fresh()->ip_address);
        $this->assertSame($other->id, $otherLog->fresh()->user_id);
    }

    /**
     * Login real com sessão em banco, reenviando o cookie de sessão como o
     * navegador faz. O cliente de testes não reenvia cookies sozinho, e em
     * requisições JSON só os envia com withCredentials().
     */
    private function loginWithDatabaseSession(): void
    {
        config(['session.driver' => 'database']);

        $login = $this->fromDashboard()
            ->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'senhaSegura123'])
            ->assertOk();

        $cookie = $login->getCookie(config('session.cookie'));
        $this->withCredentials()->withCookie($cookie->getName(), $cookie->getValue());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $this->user->id)->count());

        // Em produção cada requisição sobe uma aplicação nova. No PHPUnit ela
        // persiste, e instâncias de guard da requisição anterior mascarariam o
        // problema; descartá-las reproduz o ciclo de vida real.
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
    }

    /** Regressão: a sessão nova era gravada com o user_id do usuário excluído. */
    public function test_no_session_row_keeps_deleted_user_id(): void
    {
        $this->loginWithDatabaseSession();

        $this->fromDashboard()->deleteJson('/api/me', ['password' => 'senhaSegura123'])->assertNoContent();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }

    public function test_logout_does_not_keep_user_id_in_new_session(): void
    {
        $this->loginWithDatabaseSession();

        $this->fromDashboard()->postJson('/api/auth/logout')->assertNoContent();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }

    public function test_session_ends_after_deletion(): void
    {
        $this->deleteAccount('senhaSegura123')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->fromDashboard()->getJson('/api/me')->assertUnauthorized();
    }
}

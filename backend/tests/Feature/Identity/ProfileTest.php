<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Support\TimezoneDetails;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Routine\Models\RoutineBlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\Fakes\FakeNexoGateway;
use Tests\TestCase;

/** Perfil e fuso (FASE 4, etapa 2). Dados fictícios. */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'name' => 'Pessoa Teste',
            'email' => 'pessoa@example.com',
            'password' => 'senhaFicticia123',
            'timezone' => 'America/Sao_Paulo',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_exige_sessao(): void
    {
        $this->patchJson('/api/me', ['name' => 'X'])->assertUnauthorized();
        $this->putJson('/api/me/email', [])->assertUnauthorized();
        $this->putJson('/api/me/password', [])->assertUnauthorized();
        $this->getJson('/api/timezones')->assertUnauthorized();
    }

    public function test_atualiza_nome_e_fuso_normalizando_e_auditando_so_os_campos(): void
    {
        Sanctum::actingAs($this->user);

        $this->patchJson('/api/me', ['name' => '  Pessoa   Nova  ', 'timezone' => 'Europe/Lisbon'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pessoa Nova')
            ->assertJsonPath('data.timezone', 'Europe/Lisbon')
            ->assertJsonStructure(['data' => ['timezone_details' => ['offset', 'abbreviation', 'is_dst', 'local_time', 'next_transition']]]);

        $log = AuditLog::where('user_id', $this->user->id)->where('action', 'account.profile_updated')->sole();
        $this->assertSame(['fields' => ['name', 'timezone']], $log->metadata);
        $this->assertStringNotContainsString('Pessoa Nova', json_encode($log->toArray()));
    }

    public function test_sem_mudanca_nao_audita(): void
    {
        Sanctum::actingAs($this->user);
        $this->patchJson('/api/me', ['name' => 'Pessoa Teste'])->assertOk();
        $this->assertFalse(AuditLog::where('action', 'account.profile_updated')->exists());
    }

    public function test_validacao_do_perfil(): void
    {
        Sanctum::actingAs($this->user);

        $this->patchJson('/api/me', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/me', ['name' => '   '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/me', ['name' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/me', ['name' => "Nome\u{200B}Oculto"])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/me', ['timezone' => 'America/Atlantida'])->assertUnprocessable()
            ->assertJsonPath('errors.timezone.0', 'Fuso horário desconhecido. Use um identificador IANA, como America/Sao_Paulo.');
        $this->patchJson('/api/me', ['timezone' => '-03:00'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        // E-mail e senha não mudam por esta rota.
        $this->patchJson('/api/me', ['name' => 'Ok', 'email' => 'outro@example.com', 'password' => 'x'])->assertOk();

        $fresh = $this->user->fresh();
        $this->assertSame('pessoa@example.com', $fresh->email);
        $this->assertTrue(Hash::check('senhaFicticia123', $fresh->password));
    }

    public function test_so_altera_a_propria_conta(): void
    {
        $other = User::factory()->create(['name' => 'Outra Pessoa', 'timezone' => 'Asia/Tokyo']);
        Sanctum::actingAs($this->user);

        $this->patchJson('/api/me', ['name' => 'Mudou', 'timezone' => 'UTC', 'id' => $other->id])->assertOk();

        $this->assertSame(['Outra Pessoa', 'Asia/Tokyo'], [$other->fresh()->name, $other->fresh()->timezone]);
        $this->assertSame('Mudou', $this->user->fresh()->name);
    }

    public function test_lista_de_fusos_e_a_mesma_da_validacao(): void
    {
        Sanctum::actingAs($this->user);
        $zones = $this->getJson('/api/timezones')->assertOk()->json('data');

        $this->assertContains('America/Sao_Paulo', $zones);
        $this->assertContains('America/Noronha', $zones);
        $this->assertSame(\DateTimeZone::listIdentifiers(), $zones);
    }

    public function test_o_nexo_recebe_o_fuso_novo_na_mensagem_seguinte_e_nunca_o_nome(): void
    {
        $gateway = new FakeNexoGateway;
        $this->app->instance(NexoGateway::class, $gateway);
        Sanctum::actingAs($this->user);
        CarbonImmutable::setTestNow('2026-11-18 12:00:00 UTC');

        $this->postJson('/api/nexo/messages', ['message' => 'Que horas são aí?'])->assertOk();
        $this->patchJson('/api/me', ['timezone' => 'Asia/Tokyo', 'name' => 'Nome Secreto'])->assertOk();
        $this->postJson('/api/nexo/messages', ['message' => 'E agora?'])->assertOk();

        [$before, $after] = [$gateway->replies[0]['context'], $gateway->replies[1]['context']];
        $this->assertSame('America/Sao_Paulo', $before->timezone);
        $this->assertSame('2026-11-18T09:00:00-03:00', $before->localDateTime);
        $this->assertSame('Asia/Tokyo', $after->timezone);
        $this->assertSame('2026-11-18T21:00:00+09:00', $after->localDateTime);
        $this->assertStringNotContainsString('Nome Secreto', json_encode($after->toArray()));
    }

    public function test_a_rotina_de_hoje_do_nexo_segue_o_dia_no_fuso_da_conta(): void
    {
        $gateway = new FakeNexoGateway;
        $this->app->instance(NexoGateway::class, $gateway);
        Sanctum::actingAs($this->user);
        $this->putJson('/api/nexo/consents/nexo.context.routine', ['granted' => true, 'policy_version' => config('nexo.memory_policy_version')])->assertOk();

        // Quarta 2026-11-18 23:30 em São Paulo = quinta 2026-11-19 11:30 em Auckland.
        CarbonImmutable::setTestNow('2026-11-19 02:30:00 UTC');
        $this->postJson('/api/routine-blocks', ['title' => 'Bloco de quarta', 'category' => 'study', 'days_of_week' => [3], 'start_time' => '07:00', 'end_time' => '08:00'])->assertCreated();
        $this->postJson('/api/routine-blocks', ['title' => 'Bloco de quinta', 'category' => 'work', 'days_of_week' => [4], 'start_time' => '09:00', 'end_time' => '10:00'])->assertCreated();

        $this->postJson('/api/nexo/messages', ['message' => 'Rotina?'])->assertOk();
        $this->patchJson('/api/me', ['timezone' => 'Pacific/Auckland'])->assertOk();
        $this->postJson('/api/nexo/messages', ['message' => 'E agora?'])->assertOk();

        $this->assertStringContainsString('Bloco de quarta', json_encode($gateway->replies[0]['context']->toArray()));
        $this->assertStringContainsString('Bloco de quinta', json_encode($gateway->replies[1]['context']->toArray()));
        $this->assertStringNotContainsString('Bloco de quarta', json_encode($gateway->replies[1]['context']->toArray()));
        $this->assertSame(2, RoutineBlock::count());
    }

    public function test_horario_de_verao_nos_detalhes_e_no_horario_local_enviado_ao_nexo(): void
    {
        // Nova York: horário de verão começa em 2026-03-08, 02:00 locais (07:00 UTC).
        $before = TimezoneDetails::for('America/New_York', CarbonImmutable::parse('2026-03-08 06:30:00 UTC'));
        $this->assertSame(['-05:00', false], [$before['offset'], $before['is_dst']]);
        $this->assertSame('2026-03-08T03:00:00-04:00', $before['next_transition']['at']);
        $this->assertSame(['-04:00', true], [$before['next_transition']['offset'], $before['next_transition']['is_dst']]);

        $after = TimezoneDetails::for('America/New_York', CarbonImmutable::parse('2026-03-08 07:30:00 UTC'));
        $this->assertSame(['-04:00', true, '2026-03-08T03:30:00-04:00'], [$after['offset'], $after['is_dst'], $after['local_time']]);
        $this->assertSame('-05:00', $after['next_transition']['offset']); // volta em novembro

        // Brasil sem horário de verão desde 2019: nenhuma mudança prevista.
        $brazil = TimezoneDetails::for('America/Sao_Paulo', CarbonImmutable::parse('2026-11-18 12:00:00 UTC'));
        $this->assertSame(['-03:00', false, null], [$brazil['offset'], $brazil['is_dst'], $brazil['next_transition']]);

        // E o contexto do NEXO usa o deslocamento certo dos dois lados da mudança.
        $gateway = new FakeNexoGateway;
        $this->app->instance(NexoGateway::class, $gateway);
        $this->user->update(['timezone' => 'America/New_York']);
        Sanctum::actingAs($this->user);
        CarbonImmutable::setTestNow('2026-03-08 06:30:00 UTC');
        $this->postJson('/api/nexo/messages', ['message' => 'antes'])->assertOk();
        CarbonImmutable::setTestNow('2026-03-08 07:30:00 UTC');
        $this->postJson('/api/nexo/messages', ['message' => 'depois'])->assertOk();

        $this->assertSame('2026-03-08T01:30:00-05:00', $gateway->replies[0]['context']->localDateTime);
        $this->assertSame('2026-03-08T03:30:00-04:00', $gateway->replies[1]['context']->localDateTime);
    }

    public function test_troca_de_email_exige_senha_e_respeita_unicidade(): void
    {
        User::factory()->create(['email' => 'ocupado@example.com']);
        Sanctum::actingAs($this->user);

        $this->putJson('/api/me/email', ['email' => 'novo@example.com', 'current_password' => 'errada123'])
            ->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'A senha atual está incorreta.');
        $this->putJson('/api/me/email', ['email' => 'OCUPADO@example.com', 'current_password' => 'senhaFicticia123'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Este e-mail já está em uso.');
        $this->assertSame('pessoa@example.com', $this->user->fresh()->email);

        $this->putJson('/api/me/email', ['email' => ' Novo@Example.com ', 'current_password' => 'senhaFicticia123'])
            ->assertOk()->assertJsonPath('data.email', 'novo@example.com');

        $log = AuditLog::where('action', 'account.email_changed')->sole();
        $this->assertStringNotContainsString('novo@example.com', json_encode($log->toArray()));
    }

    public function test_troca_de_senha_encerra_as_outras_sessoes_e_mantem_a_atual(): void
    {
        // Duas sessões reais do mesmo usuário: a "outra" é gravada direto, como um segundo navegador.
        DB::table('sessions')->insert([
            'id' => str_repeat('o', 40), 'user_id' => $this->user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'outro navegador', 'payload' => base64_encode('a:0:{}'), 'last_activity' => time(),
        ]);
        $this->fromDashboard()->postJson('/api/auth/login', ['email' => 'pessoa@example.com', 'password' => 'senhaFicticia123'])->assertOk();

        $this->fromDashboard()->putJson('/api/me/password', [
            'current_password' => 'senhaFicticia123', 'password' => 'outraSenha456', 'password_confirmation' => 'outraSenha456',
        ])->assertOk()->assertJsonPath('data.other_sessions_ended', 1);

        $this->assertFalse(DB::table('sessions')->where('id', str_repeat('o', 40))->exists());
        $this->fromDashboard()->getJson('/api/me')->assertOk(); // a sessão atual continua
        $this->assertTrue(Hash::check('outraSenha456', $this->user->fresh()->password));
        $this->assertTrue(AuditLog::where('action', 'account.password_changed')->exists());
    }

    public function test_validacao_da_senha_nova(): void
    {
        Sanctum::actingAs($this->user);
        $put = fn (array $body) => $this->putJson('/api/me/password', $body + ['current_password' => 'senhaFicticia123']);

        $put(['password' => 'curta1', 'password_confirmation' => 'curta1'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => 'semNumerosAqui', 'password_confirmation' => 'semNumerosAqui'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => 'outraSenha456', 'password_confirmation' => 'diferente456'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $put(['password' => 'senhaFicticia123', 'password_confirmation' => 'senhaFicticia123'])->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'A nova senha precisa ser diferente da atual.');
        $this->putJson('/api/me/password', ['current_password' => 'errada123', 'password' => 'outraSenha456', 'password_confirmation' => 'outraSenha456'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('senhaFicticia123', $this->user->fresh()->password));
    }
}

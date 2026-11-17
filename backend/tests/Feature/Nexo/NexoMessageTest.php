<?php

namespace Tests\Feature\Nexo;

use App\Models\User;
use App\Modules\Devices\Models\Device;
use App\Modules\Nexo\Contracts\NexoGateway;
use App\Modules\Nexo\Exceptions\AiFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeNexoGateway;
use Tests\TestCase;

class NexoMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FakeNexoGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'America/Manaus']);
        $this->useGateway(new FakeNexoGateway);
    }

    private function useGateway(FakeNexoGateway $gateway): void
    {
        $this->gateway = $gateway;
        $this->app->instance(NexoGateway::class, $gateway);
    }

    public function test_replies_with_authorized_context_only(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/nexo/messages', ['message' => 'Como organizo minha semana?'])
            ->assertOk()
            ->assertJsonPath('data.reply', 'Resposta de teste do NEXO.')
            ->assertJsonPath('data.prompt_version', 'nexo-system@test');

        $sent = $this->gateway->replies[0];
        $this->assertSame('Como organizo minha semana?', $sent['message']);
        $this->assertSame('America/Manaus', $sent['context']->timezone);
        // Nome e e-mail não são enviados à IA.
        $this->assertStringNotContainsString($this->user->email, json_encode($sent['context']->toArray()));
    }

    public function test_returns_503_with_reason_when_ai_is_down(): void
    {
        $this->useGateway(new FakeNexoGateway(failure: AiFailure::Timeout));
        Sanctum::actingAs($this->user);

        $this->postJson('/api/nexo/messages', ['message' => 'oi'])
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('reason', 'timeout')
            ->assertJsonMissingPath('data');
    }

    public function test_core_features_keep_working_when_ai_is_down(): void
    {
        $this->useGateway(new FakeNexoGateway(failure: AiFailure::Unavailable));
        Sanctum::actingAs($this->user);

        $this->getJson('/api/nexo/status')->assertOk()->assertJsonPath('data.available', false);

        $this->postJson('/api/routine-blocks', [
            'title' => 'Estudo', 'category' => 'study', 'days_of_week' => [1],
            'start_time' => '19:00', 'end_time' => '20:00',
        ])->assertCreated();
        $this->postJson('/api/goals', ['title' => 'Aprender SQL', 'type' => 'learning'])->assertCreated();
        $this->getJson('/api/devices')->assertOk();
    }

    public function test_validates_message(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/nexo/messages', ['message' => '   '])->assertUnprocessable();
        $this->postJson('/api/nexo/messages', ['message' => str_repeat('a', 4001)])->assertUnprocessable();
        $this->postJson('/api/nexo/messages', ['message' => ['array']])->assertUnprocessable();

        $this->assertSame([], $this->gateway->replies);
    }

    public function test_rate_limits_messages_per_user(): void
    {
        Sanctum::actingAs($this->user);

        foreach (range(1, 10) as $_) {
            $this->postJson('/api/nexo/messages', ['message' => 'oi'])->assertOk();
        }

        $this->postJson('/api/nexo/messages', ['message' => 'oi'])->assertTooManyRequests();
        $this->assertCount(10, $this->gateway->replies);
    }

    public function test_requires_user_credentials(): void
    {
        $this->postJson('/api/nexo/messages', ['message' => 'oi'])->assertUnauthorized();

        $device = Device::factory()->for($this->user)->create();
        $this->asDevice($device->issueToken()->plainText)->postJson('/api/nexo/messages', ['message' => 'oi'])->assertForbidden();

        $this->assertSame([], $this->gateway->replies);
    }
}

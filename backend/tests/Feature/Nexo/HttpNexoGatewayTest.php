<?php

namespace Tests\Feature\Nexo;

use App\Modules\Nexo\Data\EmbeddingPurpose;
use App\Modules\Nexo\Data\ReplyContext;
use App\Modules\Nexo\Data\Turn;
use App\Modules\Nexo\Exceptions\AiFailure;
use App\Modules\Nexo\Exceptions\AiUnavailableException;
use App\Modules\Nexo\Gateway\HttpNexoGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HttpNexoGatewayTest extends TestCase
{
    private const URL = 'http://ai-service.test';

    private const TOKEN = 'token-de-servico-com-mais-de-32-caracteres';

    private function gateway(?string $token = self::TOKEN, int $dimensions = 3): HttpNexoGateway
    {
        return new HttpNexoGateway(self::URL, $token, 1, 5, 5, 1, $dimensions);
    }

    private function context(): ReplyContext
    {
        return new ReplyContext('America/Sao_Paulo', '2026-11-17T20:00:00-03:00');
    }

    private function assertFailure(AiFailure $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('AiUnavailableException não foi lançada');
        } catch (AiUnavailableException $e) {
            $this->assertSame($expected, $e->reason);
        }
    }

    public function test_reply_sends_token_and_context(): void
    {
        Http::fake([self::URL.'/v1/nexo/reply' => Http::response([
            'reply' => 'Vamos com calma.', 'model' => 'qwen3:8b', 'prompt_version' => 'nexo-system@abc',
        ])]);

        $reply = $this->gateway()->reply('Estou cansado', $this->context());

        $this->assertSame('Vamos com calma.', $reply->text);
        $this->assertSame('qwen3:8b', $reply->model);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $r['message'] === 'Estou cansado'
            && $r['context'] === ['timezone' => 'America/Sao_Paulo', 'local_datetime' => '2026-11-17T20:00:00-03:00', 'memories' => []]
            && $r['history'] === []);
    }

    public function test_reply_strips_leaked_reasoning(): void
    {
        Http::fake([self::URL.'/*' => Http::response([
            'reply' => "pensando...\n</think>\nResposta limpa.", 'model' => 'm', 'prompt_version' => 'p',
        ])]);

        $this->assertSame('Resposta limpa.', $this->gateway()->reply('oi', $this->context())->text);
    }

    public static function failingResponses(): array
    {
        return [
            'indisponível' => [503, ['error' => 'ai_unavailable'], AiFailure::Unavailable],
            'ocupado' => [503, ['error' => 'ai_busy'], AiFailure::Busy],
            'timeout do modelo' => [504, ['error' => 'ai_timeout'], AiFailure::Timeout],
            'resposta inválida do modelo' => [502, ['error' => 'ai_invalid_response'], AiFailure::InvalidResponse],
            'token divergente' => [401, ['detail' => 'x'], AiFailure::Misconfigured],
            'contrato violado' => [422, ['detail' => []], AiFailure::Misconfigured],
            'erro interno' => [500, [], AiFailure::Unavailable],
            'sem campo reply' => [200, ['model' => 'm', 'prompt_version' => 'p'], AiFailure::InvalidResponse],
            'reply vazio' => [200, ['reply' => '  ', 'model' => 'm', 'prompt_version' => 'p'], AiFailure::InvalidResponse],
            'só raciocínio' => [200, ['reply' => '<think>x</think>', 'model' => 'm', 'prompt_version' => 'p'], AiFailure::InvalidResponse],
            'tipo errado' => [200, ['reply' => ['x'], 'model' => 'm', 'prompt_version' => 'p'], AiFailure::InvalidResponse],
        ];
    }

    #[DataProvider('failingResponses')]
    public function test_reply_maps_failures(int $status, array $body, AiFailure $expected): void
    {
        Http::fake([self::URL.'/*' => Http::response($body, $status)]);

        $this->assertFailure($expected, fn () => $this->gateway()->reply('oi', $this->context()));
    }

    public function test_reply_with_non_json_body_is_invalid(): void
    {
        Http::fake([self::URL.'/*' => Http::response('<html>erro</html>', 200)]);

        $this->assertFailure(AiFailure::InvalidResponse, fn () => $this->gateway()->reply('oi', $this->context()));
    }

    public function test_read_timeout_maps_to_timeout(): void
    {
        Http::fake([self::URL.'/*' => fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 90001 milliseconds with 0 bytes received')]);

        $this->assertFailure(AiFailure::Timeout, fn () => $this->gateway()->reply('oi', $this->context()));
    }

    /** Regressão: com o container parado, o connect timeout era reportado como lentidão da IA. */
    public function test_connect_or_dns_timeout_maps_to_unavailable(): void
    {
        Http::fake([self::URL.'/*' => fn () => throw new ConnectionException(
            'cURL error 28: Connection timed out after 2001 milliseconds')]);

        $this->assertFailure(AiFailure::Unavailable, fn () => $this->gateway()->reply('oi', $this->context()));
    }

    public function test_dns_resolution_timeout_maps_to_unavailable(): void
    {
        Http::fake([self::URL.'/*' => fn () => throw new ConnectionException(
            'cURL error 28: Resolving timed out after 2000 milliseconds')]);

        $this->assertFailure(AiFailure::Unavailable, fn () => $this->gateway()->reply('oi', $this->context()));
    }

    public function test_connection_refused_maps_to_unavailable(): void
    {
        Http::fake([self::URL.'/*' => fn () => throw new ConnectionException('cURL error 7: Connection refused')]);

        $this->assertFailure(AiFailure::Unavailable, fn () => $this->gateway()->reply('oi', $this->context()));
    }

    public function test_missing_token_never_calls_the_service(): void
    {
        Http::fake();

        $this->assertFailure(AiFailure::Misconfigured, fn () => $this->gateway(token: null)->reply('oi', $this->context()));
        Http::assertNothingSent();
    }

    public function test_embed_validates_dimensions(): void
    {
        Http::fake([self::URL.'/v1/embed' => Http::sequence()
            ->push(['model' => 'e', 'dimensions' => 3, 'vectors' => [[0.1, 0.2, 0.3], [1, 0, 0]]])
            ->push(['model' => 'e', 'dimensions' => 3, 'vectors' => [[0.1, 0.2]]])
            ->push(['model' => 'e', 'dimensions' => 3, 'vectors' => [[0.1, 'x', 0.3]]]),
        ]);

        $ok = $this->gateway()->embed(['a', 'b']);
        $this->assertSame([1.0, 0.0, 0.0], $ok->vectors[1]);

        $this->assertFailure(AiFailure::InvalidResponse, fn () => $this->gateway()->embed(['a']));
        $this->assertFailure(AiFailure::InvalidResponse, fn () => $this->gateway()->embed(['a']));
    }

    public function test_reply_sends_history_and_authorized_context(): void
    {
        Http::fake([self::URL.'/v1/nexo/reply' => Http::response(['reply' => 'Ok.', 'model' => 'm', 'prompt_version' => 'p'])]);

        $context = $this->context()->with(
            [['kind' => 'preferencia', 'content' => 'Prefere estudar de manhã.']],
            ['routine_today' => ['07:00–08:00 Estudo'], 'goals' => []],
        );
        $this->gateway()->reply('E hoje?', $context, [new Turn('user', 'Oi'), new Turn('assistant', 'Olá!')]);

        Http::assertSent(fn (Request $r) => $r['history'] === [
            ['role' => 'user', 'content' => 'Oi'],
            ['role' => 'assistant', 'content' => 'Olá!'],
        ]
            && $r['context']['memories'] === [['kind' => 'preferencia', 'content' => 'Prefere estudar de manhã.']]
            && $r['context']['profile']['routine_today'] === ['07:00–08:00 Estudo']);
    }

    public function test_embed_sends_purpose(): void
    {
        Http::fake([self::URL.'/v1/embed' => Http::response(['model' => 'e', 'dimensions' => 3, 'vectors' => [[1, 0, 0]]])]);

        $this->gateway()->embed(['quando estudar?'], EmbeddingPurpose::Query);
        $this->gateway()->embed(['Prefere manhãs.']);

        Http::assertSent(fn (Request $r) => $r['purpose'] === 'query');
        Http::assertSent(fn (Request $r) => $r['purpose'] === 'document');
    }

    public function test_suggest_memories_parses_contract(): void
    {
        Http::fake([self::URL.'/v1/nexo/suggest' => Http::response(['suggestions' => [
            ['kind' => 'preferencia', 'content' => 'Prefere manhãs.', 'rationale' => 'Você disse.', 'sensitivity' => 'normal'],
            ['kind' => 'padrao', 'content' => 'Outra.', 'rationale' => '', 'sensitivity' => 'normal'],
        ], 'model' => 'm', 'prompt_version' => 'p'])]);

        $suggestions = $this->gateway()->suggestMemories(new Turn('user', 'a'), new Turn('assistant', 'b'), 1);

        $this->assertCount(1, $suggestions);
        $this->assertSame('Prefere manhãs.', $suggestions[0]->content);
        Http::assertSent(fn (Request $r) => $r['max_suggestions'] === 1 && count($r['exchange']) === 2);
    }

    public function test_suggest_memories_rejects_malformed_items(): void
    {
        Http::fake([self::URL.'/v1/nexo/suggest' => Http::sequence()
            ->push(['suggestions' => 'x'])
            ->push(['suggestions' => [['kind' => 'preferencia', 'content' => ['x'], 'rationale' => '', 'sensitivity' => 'normal']]]),
        ]);

        $call = fn () => $this->gateway()->suggestMemories(new Turn('user', 'a'), new Turn('assistant', 'b'), 2);
        $this->assertFailure(AiFailure::InvalidResponse, $call);
        $this->assertFailure(AiFailure::InvalidResponse, $call);
    }

    public function test_is_available_never_throws(): void
    {
        Http::fake([self::URL.'/health' => Http::sequence()
            ->push(['status' => 'ok', 'provider' => 'up'])
            ->push(['status' => 'ok', 'provider' => 'down'])
            ->whenEmpty(fn () => throw new ConnectionException('cURL error 7')),
        ]);

        $this->assertTrue($this->gateway()->isAvailable());
        $this->assertFalse($this->gateway()->isAvailable());
        $this->assertFalse($this->gateway()->isAvailable());
    }
}

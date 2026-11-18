<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

/**
 * Dashboard servido pelo Laravel na mesma origem da API (ADR-0010).
 * Cada teste usa uma pasta temporária de versões; nada toca storage/app/dashboard real.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pm-dashboard-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root);
        config(['dashboard.path' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function publish(string $id, string $marker = 'v1', bool $activate = true): void
    {
        File::ensureDirectoryExists("{$this->root}/releases/{$id}");
        File::put("{$this->root}/releases/{$id}/index.html", "<!doctype html><html><head><script type=\"module\" src=\"/app/assets/index-{$marker}.js\"></script></head><body><div id=\"root\"></div></body></html>");
        if ($activate) {
            File::put("{$this->root}/current", $id."\n");
        }
    }

    public function test_sem_versao_publicada_responde_503_explicando(): void
    {
        $this->get('/')
            ->assertStatus(503)
            ->assertSee('Dashboard ainda não publicado')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_rotas_da_interface_devolvem_o_index_da_versao_ativa_sem_cache(): void
    {
        $this->publish('20261118-abc1234');

        foreach (['/', '/entrar', '/memorias', '/historico/0194f0a0-0000-7000-8000-000000000000', '/meus-dados'] as $path) {
            $response = $this->get($path)->assertOk();
            $response->assertSee('/app/assets/index-v1.js', false);
            $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
            $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
            $response->assertHeader('X-Dashboard-Release', '20261118-abc1234');
            $this->assertNotEmpty($response->headers->get('ETag'));
        }
    }

    public function test_cabecalhos_de_seguranca_e_csp_so_com_a_propria_origem(): void
    {
        $this->publish('r1');
        $response = $this->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringNotContainsString('http', $csp);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_etag_igual_responde_304_e_nova_versao_muda_o_etag(): void
    {
        $this->publish('r1', 'v1');
        $etag = $this->get('/')->headers->get('ETag');

        $this->get('/', ['If-None-Match' => $etag])->assertStatus(304);

        $this->publish('r2', 'v2');
        $this->get('/', ['If-None-Match' => $etag])
            ->assertOk()
            ->assertSee('index-v2.js', false)
            ->assertHeader('X-Dashboard-Release', 'r2');
    }

    public function test_reverter_e_so_trocar_o_ponteiro(): void
    {
        $this->publish('r1', 'v1');
        $this->publish('r2', 'v2');
        File::put("{$this->root}/current", 'r1');

        $this->get('/')->assertSee('index-v1.js', false)->assertHeader('X-Dashboard-Release', 'r1');
    }

    public function test_ponteiro_invalido_ou_com_travessia_nao_vira_caminho(): void
    {
        $this->publish('r1');
        foreach (['../../../../etc', 'r1/../r1', '', 'inexistente', "r1\0x", 'r1 r2', 'r1/'] as $pointer) {
            File::put("{$this->root}/current", $pointer);
            $this->get('/')->assertStatus(503);
        }
    }

    public function test_a_interface_nao_cria_sessao_nem_cookies(): void
    {
        $this->publish('r1');
        $before = DB::table('sessions')->count();

        $response = $this->get('/memorias');

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies(), 'A rota da interface não pode emitir cookies.');
        $this->assertSame($before, DB::table('sessions')->count());
    }

    public function test_fallback_nao_captura_api_sanctum_health_nem_arquivos(): void
    {
        $this->publish('r1');

        $this->getJson('/api/rota-que-nao-existe')->assertNotFound()->assertJsonStructure(['message']);
        $this->get('/api/rota-que-nao-existe')->assertNotFound()->assertHeaderMissing('X-Dashboard-Release');
        $this->get('/api')->assertNotFound()->assertHeaderMissing('X-Dashboard-Release');
        $this->getJson('/api/me')->assertUnauthorized();
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->get('/up')->assertOk()->assertHeaderMissing('X-Dashboard-Release');

        // Asset ausente nunca recebe HTML (o navegador quebraria com MIME errado).
        $this->get('/app/assets/index-antigo.js')->assertNotFound()->assertHeaderMissing('X-Dashboard-Release');
        $this->get('/qualquer/arquivo.js')->assertNotFound()->assertHeaderMissing('X-Dashboard-Release');
    }

    public function test_metodos_de_escrita_na_interface_nao_sao_aceitos(): void
    {
        $this->publish('r1');
        $this->post('/memorias')->assertStatus(405);
    }

    /**
     * A sessão nasce só quando o app pede o cookie CSRF. (A recusa sem token não é testável
     * aqui: o Laravel desliga a verificação de CSRF em testes; o E2E no Chromium cobre isso.)
     */
    public function test_a_sessao_nasce_so_no_cookie_csrf_e_o_login_funciona_na_mesma_origem(): void
    {
        $this->publish('r1');
        $user = User::factory()->create(['password' => 'senhaFicticia12345']);
        $before = DB::table('sessions')->count();

        $this->get('/');
        $this->get('/entrar');
        $this->assertSame($before, DB::table('sessions')->count());

        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withHeaders(['Origin' => 'http://localhost:8000', 'Referer' => 'http://localhost:8000/entrar'])
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'senhaFicticia12345'])
            ->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}

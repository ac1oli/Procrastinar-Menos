<?php

namespace App\Modules\Web\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Web\DashboardRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Entrega o index.html do dashboard para qualquer rota da interface (ADR-0010).
 *
 * - Sem sessão: a rota não usa StartSession; a sessão só nasce em /sanctum/csrf-cookie,
 *   quando o app precisa dela. Visitas à interface não criam linhas em `sessions`.
 * - Sem cache do HTML (revalida por ETag): uma publicação nova vale no próximo carregamento.
 *   Os arquivos com hash em /app/assets têm cache longo (servidos fora do Laravel).
 * - Cabeçalhos de segurança, inclusive CSP só com a própria origem.
 */
class DashboardController extends Controller
{
    /** O dashboard não tem script inline nem recursos externos; estilos inline vêm do Radix/Sonner. */
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; "
        ."base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function __invoke(Request $request, DashboardRelease $release): Response
    {
        $id = $release->current();

        if ($id === null) {
            return $this->withSecurityHeaders(response($this->unpublishedPage(), Response::HTTP_SERVICE_UNAVAILABLE))
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->header('Cache-Control', 'no-store')
                ->header('Retry-After', '60');
        }

        $html = $release->indexHtml($id);
        $etag = '"'.hash('sha256', $id."\n".$html).'"';

        $response = in_array($etag, $request->getETags(), true)
            ? response('', Response::HTTP_NOT_MODIFIED)
            : response($html)->header('Content-Type', 'text/html; charset=UTF-8');

        return $this->withSecurityHeaders($response)
            ->header('Cache-Control', 'no-cache')
            ->header('ETag', $etag)
            ->header('X-Dashboard-Release', $id);
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response->withHeaders([
            'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ]);
    }

    private function unpublishedPage(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="pt-BR">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>NEXO · Procrastinar Menos</title></head>
            <body style="font-family: system-ui, sans-serif; max-width: 36rem; margin: 4rem auto; padding: 0 1rem; line-height: 1.5">
            <h1>Dashboard ainda não publicado</h1>
            <p>A API está no ar, mas nenhuma versão da interface foi publicada neste servidor.</p>
            <p>Para publicar, veja <code>docs/operations/dashboard-publicacao.md</code>.</p>
            </body>
            </html>
            HTML;
    }
}

<?php

/*
| Roteador do servidor embutido do PHP (`php -S`, container pm-backend). ADR-0010.
|
| - /app/assets/*: arquivos com hash no nome → cache de 1 ano, imutável.
| - outros arquivos de /app/ (ícone): revalidação a cada uso.
| - /app/ inexistente ou fora da pasta: 404 em texto, nunca o index.html.
| - todo o resto segue para o roteador padrão do Laravel.
|
| Em hospedagem com nginx/Caddy, as mesmas regras vão para a configuração do servidor web
| (docs/operations/dashboard-publicacao.md); este arquivo deixa de ser usado.
*/

$publicPath = getcwd();
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if ($uri === '/app' || str_starts_with($uri, '/app/')) {
    $base = realpath($publicPath.'/app');
    $file = realpath($publicPath.$uri);

    if ($base === false || $file === false || ! is_file($file) || ! str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo 'Arquivo não encontrado.';

        return true;
    }

    $types = [
        'js' => 'text/javascript; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'woff2' => 'font/woff2',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'json' => 'application/json',
        'txt' => 'text/plain; charset=UTF-8',
    ];
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $immutable = str_starts_with($file, $base.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR);
    $modified = filemtime($file);

    header('Content-Type: '.($types[$extension] ?? 'application/octet-stream'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: '.($immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate'));
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', $modified).' GMT');

    $since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
    if ($since !== null && strtotime($since) >= $modified) {
        http_response_code(304);

        return true;
    }

    header('Content-Length: '.filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($file);
    }

    return true;
}

return require $publicPath.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';

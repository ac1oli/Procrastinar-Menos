# ADR-0010: dashboard servido pelo Laravel, na mesma origem da API

- **Status:** proposta (aguardando revisão do proprietário)
- **Data:** 2026-11-18
- **Relacionados:** [ADR-0005](0005-autenticacao-e-dispositivos.md) (sessão SPA),
  [ADR-0009](0009-dashboard-react.md) (dashboard)

## Contexto

Até a FASE 3, o dashboard só funcionava no dev server do Vite, que faz proxy de `/api` e `/sanctum`. A FASE 4 pede que ele funcione na mesma origem do backend Laravel, sem o Vite, com estas condições:
- manter a sessão em cookie HttpOnly e a proteção contra CSRF;
- não deixar o fallback das rotas do React capturar a API;
- tratar corretamente arquivos estáticos, cache e troca de versões;
- não expor segredos;
- permitir migrar para a nuvem sem reescrever o app.

## Decisão

1. **O build fica em `/app/`.** O `vite build` usa `base: '/app/'` e não gera source maps. O dev server continua na raiz, com o proxy de sempre.

2. **Versões imutáveis, com ponteiro.**
   - Cada publicação guarda o build inteiro em `backend/storage/app/dashboard/releases/<id>/`, fora da pasta pública. O `<id>` é a data mais o commit, com o sufixo `-local` quando a árvore tem mudanças.
   - `storage/app/dashboard/current` aponta a versão ativa; `previous` aponta a anterior.
   - Publicar, reverter e retirar do ar é só trocar o ponteiro (substituição atômica de arquivo), com `infrastructure/scripts/publish-dashboard.ps1`.

3. **Arquivos estáticos em `backend/public/app/`.**
   - Os arquivos com hash no nome são só acrescentados, nunca sobrescritos: quem está com a versão anterior aberta continua carregando os trechos dela.
   - Ficam guardadas as últimas 3 versões. Arquivos que nenhuma delas usa são removidos.
   - O `index.html` **não** fica na pasta pública.

4. **O Laravel entrega o `index.html`.** Uma rota `GET /{path?}` em `routes/dashboard.php` é registrada **fora do grupo `web`**: sem sessão, sem cookies e sem middleware de CSRF.
   - **Prefixos que ela não captura:** `api`, `sanctum`, `up`, `app` e `storage`, nem caminhos com extensão de arquivo. Um `.js` ausente responde 404 em texto, nunca HTML (o navegador quebraria com o tipo errado).
   - **Cache:** `Cache-Control: no-cache`, com ETag (o navegador revalida e recebe 304). Uma publicação nova vale no próximo carregamento.
   - **Cabeçalhos:**
     - CSP só com a própria origem (`script-src 'self'`, `connect-src 'self'`, `frame-ancestors 'none'`);
     - `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, `COOP` e `Permissions-Policy`;
     - `X-Dashboard-Release` com a versão.
   - **Sem versão publicada:** responde 503 com uma página explicativa. A API continua funcionando.

5. **Cache dos arquivos estáticos.** No container local (`php -S`), um roteador próprio (`backend/server.php`) serve `/app/*` com estes cabeçalhos:
   - `/app/assets/*`: `public, max-age=31536000, immutable`;
   - demais arquivos: revalidação.

   Também confere o tipo correto, `nosniff`, `Last-Modified`/304 e bloqueia a saída da pasta. O resto segue para o roteador padrão do Laravel. Em nginx ou Caddy, as mesmas regras vão para a configuração do servidor web (ver o guia de operação).

6. **Troca de versão com a página aberta.** Se um trecho preguiçoso não carregar, o app recarrega uma vez (`vite:preloadError`) e busca o `index.html` novo. Isso cobre, por exemplo, uma versão antiga já removida. Não há laço: só uma vez por carregamento.

7. **Sessão, CSRF e segredos.**
   - Continua a mesma sessão Sanctum (cookie HttpOnly), agora na própria origem do Laravel, que já estava em `SANCTUM_STATEFUL_DOMAINS`.
   - O Laravel 13 aceita escrita da própria página sem token quando o navegador envia `Sec-Fetch-Site: same-origin`, que o JavaScript não consegue forjar. Nos demais casos (outro site, outra porta, cliente sem esse cabeçalho), exige o token `X-XSRF-TOKEN`, que o app sempre envia.
   - O build não recebe variáveis de ambiente. O script de publicação recusa um build com algo parecido com um segredo ou com source maps.

## Alternativas avaliadas

- **Blade com `@vite` e o manifest:** duplicaria o `index.html` num template e acoplaria o build ao `laravel-vite-plugin`. Servir o `index.html` gerado é mais simples e igual em qualquer hospedagem.
- **Container nginx na frente do PHP:** resolveria o cache dos estáticos, mas acrescenta um serviço ao ambiente local. Fica como opção para a nuvem. O roteador do `php -S` cobre o local.
- **Copiar o build direto para `public/` sem versões:** sem reversão instantânea, e quem estivesse com a página aberta perderia os trechos da versão anterior.

## Consequências

- **Atualizar o dashboard exige republicar:** `pwsh infrastructure/scripts/publish-dashboard.ps1`. O dev server segue independente.
- **O `pm-backend` usa `backend/server.php` como roteador.** Mudanças nele exigem `docker restart pm-backend`.
- **Visitar a interface não cria sessão.** O app cria uma sessão anônima ao consultar `/api/me` na abertura, como já fazia no dev server, e ela vence em 120 min.
- **Cookies de `localhost` não distinguem porta.** No mesmo navegador, os backends de `:8000`, `:8010` e `:8011` sobrescrevem o cookie de sessão uns dos outros. Use uma origem por vez; na nuvem, cada ambiente tem o próprio domínio.
- **Na nuvem:**
  - `SESSION_SECURE_COOKIE=true`, HTTPS, `APP_DEBUG=false` e `SANCTUM_STATEFUL_DOMAINS` com o domínio público;
  - estáticos em CDN ou no servidor web com as regras deste ADR;
  - `storage/app/dashboard` em volume persistente, ou publicado junto com a imagem.

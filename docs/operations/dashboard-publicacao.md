# Dashboard: inicialização, build, publicação e reversão

Como colocar o dashboard no ar servido pelo Laravel, na mesma origem da API, sem o dev server do Vite. Decisão e motivos no [ADR-0010](../decisions/0010-dashboard-na-mesma-origem.md).

## 1. Iniciar a aplicação (produção local)

```powershell
# Stack: banco, backend, fila, agendador e IA (só em 127.0.0.1)
docker compose -f infrastructure/docker/compose.yml --env-file infrastructure/docker/.env up -d --build

# Publicar a versão atual do dashboard
pwsh infrastructure/scripts/publish-dashboard.ps1
```

Acesse **http://localhost:8000**. Use sempre o mesmo endereço: `localhost` e `127.0.0.1` são origens diferentes para o navegador e têm cookies separados.

Sem nenhuma versão publicada, a interface responde 503 com uma explicação, e a API continua no ar.

## 2. Desenvolvimento (separado)

```powershell
cd dashboard; pnpm dev    # http://localhost:5173, proxy de /api e /sanctum para :8000
```

O dev server não depende da publicação, e a publicação não depende do dev server.

## 3. Publicar

```powershell
pwsh infrastructure/scripts/publish-dashboard.ps1            # build + publicação
pwsh infrastructure/scripts/publish-dashboard.ps1 -SkipBuild # publica o dashboard/dist já gerado
```

O script:
1. roda `pnpm build` (TypeScript estrito + Vite, `base: /app/`, sem source maps);
2. recusa o build se encontrar algo parecido com um segredo (`APP_KEY`, `AI_SERVICE_TOKEN`, `POSTGRES_PASSWORD`, chave privada, `pm_runtime`) ou source maps;
3. guarda a versão em `backend/storage/app/dashboard/releases/<data>-<commit>[-local]/`;
4. acrescenta os arquivos estáticos em `backend/public/app/`, sem apagar os da versão anterior;
5. troca o ponteiro `backend/storage/app/dashboard/current`;
6. mantém as últimas 3 versões (`-Keep N` muda isso) e remove os arquivos que nenhuma delas usa;
7. mostra a versão que o servidor está entregando (cabeçalho `X-Dashboard-Release`).

Não há reinício de container nem acesso ao banco. Quem estiver com a página aberta pega a versão nova no próximo carregamento. Se o app pedir um trecho que não existe mais, ele recarrega sozinho uma vez.

## 4. Consultar e reverter

```powershell
pwsh infrastructure/scripts/publish-dashboard.ps1 -List       # versões guardadas (atual e anterior)
pwsh infrastructure/scripts/publish-dashboard.ps1 -Rollback   # volta para a anterior
pwsh infrastructure/scripts/publish-dashboard.ps1 -To <id>    # ativa uma versão guardada
pwsh infrastructure/scripts/publish-dashboard.ps1 -Unpublish  # tira a interface do ar (a API continua)
```

A reversão é instantânea: só troca o ponteiro. Depois de `-Unpublish`, `-Rollback` volta para a versão que estava no ar.

### Reverter a FASE 4 inteira (servir só pelo Vite de novo)
1. `pwsh infrastructure/scripts/publish-dashboard.ps1 -Unpublish`. A interface em `:8000` passa a responder 503 e o dev server continua normal.
2. Se for preciso voltar o roteador do container: reverta o commit do `Dockerfile` e rode `docker compose ... up -d --build --no-deps backend`.

## 5. O que o servidor entrega

| Caminho | Quem responde | Cache | Observações |
|---|---|---|---|
| `/`, `/memorias`, `/historico/<id>`… | Laravel (`DashboardController`) | `no-cache` + ETag (304) | CSP só com a própria origem, sem cookie e sem sessão |
| `/app/assets/*` (com hash) | `backend/server.php` | 1 ano, `immutable` | 404 em texto se não existir |
| `/app/nexo.svg` | `backend/server.php` | revalida | |
| `/api/*`, `/sanctum/*`, `/up` | Laravel | — | nunca recebem o `index.html` |

Para conferir:

```powershell
curl.exe -sI http://127.0.0.1:8000/                 # X-Dashboard-Release, Cache-Control, CSP
curl.exe -sI http://127.0.0.1:8000/app/assets/<arquivo>.js
```

## 6. Testes da produção local

```powershell
pwsh infrastructure/scripts/e2e-db.ps1          # banco isolado + backends :8010 e :8011 (sem IA)
pwsh infrastructure/scripts/publish-dashboard.ps1
cd dashboard; pnpm test:e2e:laravel             # Chromium contra o dashboard servido pelo Laravel
cd ..; pwsh infrastructure/scripts/e2e-db.ps1 -Down
```

## 7. Migração para a nuvem (sem reescrever o app)

**O que não muda:**
- o build (`/app/`);
- a rota do Laravel;
- o formato das versões;
- o script de publicação: a mesma lógica pode virar um passo de CI.

**O que muda:**
- **Servidor web:** nginx/Caddy ou PHP-FPM no lugar do `php -S`, com as mesmas regras do `backend/server.php`. Exemplo para nginx:

  ```nginx
  location /app/assets/ { try_files $uri =404; add_header Cache-Control "public, max-age=31536000, immutable"; add_header X-Content-Type-Options nosniff; }
  location /app/        { try_files $uri =404; add_header Cache-Control "public, max-age=0, must-revalidate"; }
  location /            { try_files $uri /index.php?$query_string; }
  ```

  Ative gzip/brotli para `js`, `css` e `svg`. Os arquivos com hash também podem ir para uma CDN.
- **HTTPS obrigatório:** `SESSION_SECURE_COOKIE=true`, `APP_ENV=production`, `APP_DEBUG=false` e `SANCTUM_STATEFUL_DOMAINS` com o domínio público.
- **`storage/app/dashboard`:** fica em volume persistente, ou vai dentro da imagem a cada deploy (aí reverter é voltar a imagem).

# Investigação: sessão anônima de 18/11/2026, 07:34

- **Data da investigação:** 2026-11-18, por volta de 07:47 (horário de Brasília)
- **Pedido do proprietário:** descobrir a origem da sessão anônima extra no banco principal (`procrastinar_menos`), sem excluir nem alterar o registro.
- **O que foi feito:** apenas leituras. Consultei os metadados da tabela `sessions` (sem o conteúdo `payload`), os logs do container `pm-backend`, a lista de processos e a instalação do Firefox. O histórico e os dados do navegador **não** foram abertos.

## 1. Rotas que criam sessão anônima

O driver de sessão é o `database`, com tempo de vida de 120 min. As sessões vencidas são apagadas por sorteio (2% das requisições, `lottery [2, 100]`). Nesta instalação, criam uma linha em `sessions` sem usuário:

| Rota | Por quê |
|---|---|
| `GET /` (e qualquer outra rota de `routes/web.php`) | grupo `web` com `StartSession`; hoje devolve a página padrão `welcome` do Laravel |
| `GET /sanctum/csrf-cookie` | sempre inicia a sessão para gravar o token CSRF |
| `/api/*` com `Origin`/`Referer` de domínio de primeira parte (`SANCTUM_STATEFUL_DOMAINS`) | o `statefulApi()` do Sanctum inicia a sessão para clientes de primeira parte |
| `GET /up` | **não** cria sessão. Verificado: o acesso de 10:44 UTC não gerou linha nova |

## 2. Evidências

### Linhas da tabela `sessions`
Só metadados; IP e horário convertidos para Brasília.

| ID (prefixo) | Usuário | IP | Navegador declarado (User-Agent) | Última atividade |
|---|---|---|---|---|
| JJ7Mu3… | — | 172.18.0.1 | `… PowerShell/7.7.0` | 17/11 09:01:39 |
| YYRB7E… | — | 172.18.0.1 | `… PowerShell/7.7.0` | 17/11 09:02:02 |
| X94u6A… | — | 172.18.0.1 | `… PowerShell/7.7.0` | 17/11 09:04:29 |
| gEzzr4… | — | 172.18.0.1 | `… PowerShell/7.7.0` | 17/11 23:47:28 |
| **QC93uZ…** | — | 172.18.0.1 | `Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:160.0) Gecko/20100101 Firefox/160.0` | **18/11 07:34:24** |

As quatro primeiras têm o User-Agent do `Invoke-WebRequest` do PowerShell: são os testes de fumaça registrados antes. A quinta é diferente: **Firefox 160 no Windows de 64 bits**.

### Log do container `pm-backend`
O relógio do container está em UTC: 10:34 UTC = 07:34 em Brasília.

```text
[Wed Nov 18 10:34:20 2026] 172.18.0.1:50168 Accepted
[Wed Nov 18 10:34:20 2026] 172.18.0.1:50168 [GET] URI: /
[Wed Nov 18 10:34:24 2026] 172.18.0.1:50168 Closing
```

- **Um único acesso**, à página inicial. Não há pedido de `favicon.ico` nem de nenhuma rota `/api` ou `/sanctum` nesse intervalo.
- **Requisições anteriores:** a última foi `GET /api/me` às 02:47 UTC, o teste de fumaça do proxy.
- **Requisição seguinte:** `GET /up` às 10:44 UTC, a verificação feita depois da remoção do ambiente de E2E.
- **IP 172.18.0.1:** é o gateway da rede do Docker. Toda conexão vinda do próprio Windows pela porta publicada `127.0.0.1:8000` aparece assim, então o IP não distingue o processo de origem.

### Máquina local
- **Firefox:** instalado em `C:\Program Files\Mozilla Firefox`, versão **160.0**, a mesma do User-Agent da sessão. Havia um processo `firefox` rodando desde **17/11/2026 18:37**.
- **Porta 8000:** quem escuta é só o `com.docker.backend`, em `127.0.0.1`, que é o repasse do Docker Desktop. Não há registro histórico de conexões por processo: o Windows não guarda isso por padrão, e o firewall não registra tráfego de loopback.

### Ferramentas usadas na sessão de desenvolvimento

| Ferramenta | User-Agent | Porta usada em 18/11 |
|---|---|---|
| Scripts PowerShell | `PowerShell/7.x` | 8000 (smoke) |
| `curl` | `curl/x` | 8000 e 8010 |
| Testes de contrato (Node) | `node` | 8010 |
| Playwright | `HeadlessChrome` (Chromium) | 5173 → proxy → 8010 |

Nenhuma delas se identifica como Firefox, e o Firefox não foi usado em nenhum passo automatizado.

## 3. Conclusão

**Comprovado pelas evidências:**
- A sessão `QC93uZ…` foi criada por um **único `GET /`**, feito às **07:34:20** de 18/11 por um cliente que se identificou como **Firefox 160 no Windows**, a partir desta máquina (via porta `127.0.0.1:8000`).
- O Firefox 160 está instalado e estava aberto nesse horário.
- Nenhuma ferramenta automatizada do projeto usa esse User-Agent.

**Não é possível determinar com as evidências disponíveis:**
- **O que levou o Firefox a abrir a página.** Pode ter sido uma navegação manual, uma aba restaurada ou recarregada, uma extensão ou uma sugestão da barra de endereço. Distinguir isso exigiria abrir o histórico do navegador. Isso não foi feito, por ser dado pessoal fora do escopo. O próprio proprietário pode conferir no histórico do Firefox se há acesso a `localhost:8000` ou `127.0.0.1:8000` nesse horário.
- **Se o User-Agent é autêntico.** Ele é declarado pelo cliente e pode ser falsificado. Como a porta só escuta em `127.0.0.1`, a origem é necessariamente um processo nesta máquina.

**Risco:** baixo.
- A sessão não tem usuário associado e carrega só o token CSRF da visita (188 bytes).
- Está vencida desde ~09:34 e será removida pelo coletor de sessões do Laravel.
- A página inicial era a `welcome` padrão, que não expõe dados.

**Mantida sem alteração**, conforme pedido.

## 4. Mudanças relacionadas (FASE 4)

- Na branch `feat/fase4-dashboard-producao` (ADR-0010), `GET /` e as rotas da interface entregam o `index.html` **sem iniciar sessão**, o que está coberto por teste.
  - **O que deixa de acontecer:** uma visita que só carrega a página, como esta de 07:34, não cria mais linha em `sessions`.
  - **O que continua:** quando o app abre no navegador, ele consulta `/api/me`, e o Sanctum inicia uma sessão anônima, como já acontecia com o dev server. Ela vence em 120 minutos.
- Fica proposto registrar o User-Agent já presente em `sessions` também no log de acesso, para investigações futuras. Isso ainda não foi implementado; depende de decidir antes o que pode ir para log (ADR-0008, D5).

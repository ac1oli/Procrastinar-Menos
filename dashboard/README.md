# dashboard — NEXO (React + TypeScript)

> **Status:** primeira versão funcional (FASE 3), ligada à API real. Decisões no
> [ADR-0009](../docs/decisions/0009-dashboard-react.md).

Interface web do Procrastinar Menos:
- cadastro e entrada;
- onboarding com autorizações independentes;
- painel;
- conversa com o NEXO (inclusive privada);
- histórico;
- memórias e sugestões;
- privacidade, retenção, exportação e exclusão de conta;
- rotina e objetivos.

Nenhuma regra de negócio nem credencial vive aqui: o backend decide tudo, e a interface mostra
o resultado.

## Executar

Pré-requisitos: Node 24 e pnpm (nativos no Windows), com a stack do compose no ar
(`pm-backend` em `127.0.0.1:8000`).

```powershell
cd dashboard
pnpm install
pnpm dev            # http://localhost:5173 (proxy de /api e /sanctum para o backend)
```

O dev server escuta só em `localhost`. A sessão é o cookie HttpOnly do Laravel, sem tokens no
navegador.

## Testes

```powershell
pnpm test           # telas + cliente HTTP, com backend falso (MSW); não usa a rede
pnpm typecheck      # TypeScript estrito
pnpm lint
pnpm build

# Contrato com o backend REAL e a IA real, em banco isolado (nunca o principal):
pwsh ../infrastructure/scripts/e2e-db.ps1        # recria procrastinar_menos_e2e e sobe pm-backend-e2e
pnpm test:contract
pnpm test:e2e                                     # Chromium real: fluxo completo + celular (via Vite)
pnpm test:e2e:laravel                             # o mesmo + produção, com o dashboard servido pelo Laravel
pwsh ../infrastructure/scripts/e2e-db.ps1 -Down  # opcional: remove o banco e o container de E2E
```

## Estrutura

```text
src/
  api/          client.ts (CSRF, sessão, erros tipados) · endpoints.ts (uma função por rota) · types.ts
  components/   ui/ (padrão shadcn sobre radix-ui) · layout/ (AppShell, marca NEXO) · ConfirmDialog
  features/     auth · onboarding · home · chat · history · memories · privacy (Meus dados) · profile · data (exclusões com prévia) · planning
  lib/          labels (textos pt-BR) · theme (claro/escuro/sistema) · utils
  test/         server.ts (backend falso com as regras do real) · render.tsx · setup.ts
  contract/     teste de contrato contra o backend real
e2e/            Playwright no Chromium contra o backend isolado (fluxo completo e celular)
```

O `pnpm test:e2e` precisa do Chromium do Playwright. Instale uma vez, fora do repositório:
`pnpm exec playwright install --only-shell chromium`. Ele sobe o próprio dev server, apontado
para `127.0.0.1:8010`, e recusa rodar se a porta 5173 já estiver em uso.

## Convenções

- Todo acesso à API passa por `src/api/endpoints.ts`. Não use `fetch` direto nas telas.
- Mostre os erros da API (`ApiError.field()`) em vez de revalidar regras no cliente.
- Ações destrutivas usam `ConfirmDialog`. Revogação com dados segue o D7 (excluir pré-selecionado
  + confirmação).
- Textos em pt-BR ficam em `src/lib/labels.ts`. Cores e tokens da identidade ficam em
  `src/index.css`.
- Novas telas: componente em `features/`, rota preguiçosa em `App.tsx`, teste com `renderApp()`.

## Identidade visual

- **Cores:** índigo NEXO (foco e calma) e ciano de "conexão", em gradiente usado com parcimônia
  (marca, botão principal, saudação). Neutros levemente frios.
- **Tom:** nada de vermelho para cobrar a pessoa. O destrutivo aparece só em ações que apagam
  dados.
- **Marca:** um "N" que liga dois pontos (rotina, objetivos e memória conectados).
- **Temas:** claro, escuro e do sistema.

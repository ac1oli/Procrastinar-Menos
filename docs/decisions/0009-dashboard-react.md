# ADR-0009 — Dashboard React: arquitetura, sessão e testes

- **Status:** aceita
- **Data:** 2026-11-18
- **Relacionados:** [ADR-0005](0005-autenticacao-e-dispositivos.md) (sessão SPA),
  [ADR-0008](0008-memoria-do-nexo.md) (memória e consentimentos)

## Contexto

O proprietário pediu a primeira versão do dashboard. Os requisitos:
- **Funcionalidades:** cadastro e entrada, onboarding com autorizações independentes, painel,
  conversa (inclusive privada), histórico, memórias e sugestões, privacidade e retenção,
  exportação, rotina e objetivos.
- **Integração:** tudo ligado à API real.
- **Separação:** nenhuma regra de negócio nem credencial no React.
- **Interface:** identidade visual própria do NEXO, responsiva.

## Decisão

1. **Stack:** Vite 7, React 19, TypeScript 5.9 (estrito), React Router 7, TanStack Query 5,
   Tailwind CSS 4, componentes no padrão shadcn/ui sobre o pacote oficial `radix-ui`, ícones
   `lucide-react`, Inter auto-hospedada (sem requisição a CDN). Usamos as versões mais recentes
   das linhas principais cuja API conhecemos. Vite 8, React Router 8 e TypeScript 7 ficaram para
   uma atualização planejada.
2. **Mesma origem e sessão HttpOnly:**
   - em desenvolvimento, o Vite (só em `localhost:5173`) faz proxy de `/api` e `/sanctum` para o
     backend;
   - a autenticação é a sessão do Sanctum (cookie HttpOnly) + CSRF (`XSRF-TOKEN` →
     `X-XSRF-TOKEN`);
   - não há tokens, senhas ou segredos no navegador, nem `localStorage` com dados da conta (só a
     preferência de tema);
   - sem CORS.
3. **Um único cliente HTTP** (`src/api/client.ts`) e funções finas por rota
   (`src/api/endpoints.ts`):
   - o 419 (token expirado) é renovado uma vez, e o 401 leva à tela de entrada;
   - erros tipados (422 por campo, 503 com `reason` e `Retry-After`).
4. **Regras ficam no backend.** A interface só reflete o que a API decide e mostra os erros
   dela, inclusive:
   - consentimentos e dependências;
   - escolha obrigatória ao revogar;
   - mascaramento, sensibilidade e limite de fixadas;
   - limites de exportação.

   Guardas de rota decidem só a navegação.
5. **Requisitos de produto que a interface garante:**
   - **D1:** onboarding sem nenhuma opção pré-marcada e sem salvar antes de tudo ser decidido.
   - **D7:** ao revogar, "excluir" vem pré-selecionado, mas só segue com a confirmação marcada.
   - **Modo privado:** envia `private` e os turnos ficam só na tela.
   - **IA indisponível:** a mensagem volta para a caixa e nada se perde.
6. **Componentes escritos à mão no padrão shadcn**, sem o CLI. Nesta máquina, o CLI do shadcn
   (v4) instalou repetidamente um pacote npm `cn` alheio ao projeto, sem relação com o shadcn.
   Por cautela com a cadeia de suprimentos, ele não é usado. O pnpm 12 só permite o script de
   instalação do `esbuild` (`pnpm-workspace.yaml`).
7. **Testes em três camadas:**
   - **Telas** (Vitest + Testing Library + MSW): o app inteiro com rotas e guardas, contra um
     backend falso em memória que reproduz as regras do real (CSRF, consentimentos, dependências,
     escolha de dados, modo privado, 422/409/503). Nenhuma requisição sai da máquina.
   - **Contrato** (`pnpm test:contract`): as mesmas funções de `endpoints.ts`, contra o backend
     **real** e a IA real, num banco isolado (`procrastinar_menos_e2e`, container
     `pm-backend-e2e`, perfil `e2e` do compose). O banco principal não é tocado.
   - **Ponta a ponta no navegador** (`pnpm test:e2e`, Playwright + Chromium sem janela). O Vite
     sobe com o proxy apontado para o mesmo backend isolado e roda:
     - o fluxo completo, do cadastro à exclusão da conta, com a IA real;
     - o layout de celular (Pixel 7, sem rolagem horizontal, menu lateral).

     Também verifica que:
     - o cookie de sessão é HttpOnly;
     - nada da conta fica no `localStorage`;
     - todas as requisições ficam na origem do dashboard;
     - não há erros de script.

     Sem rastros, vídeos ou capturas de tela gravados. O `webServer` nunca reaproveita um dev
     server já aberto, porque ele poderia estar ligado ao banco principal.

## Alternativas avaliadas

- **Token Bearer guardado no navegador:** exposto a XSS e contraria o ADR-0005. Rejeitado.
- **CORS com cookies entre `5173` e `8000`:** mais configuração e superfície; o proxy dá mesma
  origem sem custo. Rejeitado.
- **Instalar Chrome ou Edge para o E2E:** rejeitado pelo proprietário. Ele autorizou apenas o
  Chromium do Playwright, fora do repositório (2026-11-18). Ficou só o executável sem janela
  (`chromium_headless_shell`, ~290 MB). A versão com janela foi apagada após o download porque
  os testes não a usam.
- **Roteador "data" do React Router:** sem loaders ou actions ele não traz ganho, e no jsdom
  cria `Request` incompatível com o Node. Usamos o roteador declarativo.

## Consequências

- Em produção, o dashboard precisa ser servido na **mesma origem** da API (proxy reverso ou o
  próprio Laravel servindo `dist/`). A decisão fica para a FASE 4 ou 6.
- O backend falso dos testes precisa acompanhar o contrato real. O teste de contrato é a rede de
  segurança: se o backend mudar, ele falha.
- Atualizar para Vite 8, React Router 8 e TypeScript 7 exige revisar APIs.
- **Vitest 3 → 4 (2026-11-18), na revisão de segurança:**
  - avisos críticos do `tinypool` e moderados do `@vitest/mocker` (ferramentas de teste, fora do
    build);
  - o `@vitest/coverage-v8`, sem uso, foi removido.

  Resta o aviso "alto" de ReDoS do `picomatch` (via Vite, só em desenvolvimento), sem correção
  publicada. Ele não é explorável aqui, porque os padrões vêm do próprio projeto.
- **Falha encontrada só no Chromium:** o onboarding navegava antes de atualizar o cache das
  autorizações, e a guarda devolvia a pessoa ao onboarding. Agora o estado devolvido pela API
  entra no cache antes da navegação.

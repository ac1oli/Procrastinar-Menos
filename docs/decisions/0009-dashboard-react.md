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
7. **Testes em duas camadas:**
   - **Telas** (Vitest + Testing Library + MSW): o app inteiro com rotas e guardas, contra um
     backend falso em memória que reproduz as regras do real (CSRF, consentimentos, dependências,
     escolha de dados, modo privado, 422/409/503). Nenhuma requisição sai da máquina.
   - **Contrato** (`pnpm test:contract`): as mesmas funções de `endpoints.ts`, contra o backend
     **real** e a IA real, num banco isolado (`procrastinar_menos_e2e`, container
     `pm-backend-e2e`, perfil `e2e` do compose). O banco principal não é tocado.

## Alternativas avaliadas

- **Token Bearer guardado no navegador:** exposto a XSS e contraria o ADR-0005. Rejeitado.
- **CORS com cookies entre `5173` e `8000`:** mais configuração e superfície; o proxy dá mesma
  origem sem custo. Rejeitado.
- **E2E com navegador (Playwright):** a máquina não tem Edge nem Chrome instalados, e baixar o
  Chromium (~170 MB, fora do repositório) depende de autorização. Substituído, por ora, pelo
  teste de contrato + testes de tela.
- **Roteador "data" do React Router:** sem loaders ou actions ele não traz ganho, e no jsdom
  cria `Request` incompatível com o Node. Usamos o roteador declarativo.

## Consequências

- Em produção, o dashboard precisa ser servido na **mesma origem** da API (proxy reverso ou o
  próprio Laravel servindo `dist/`). A decisão fica para a FASE 4 ou 6.
- O backend falso dos testes precisa acompanhar o contrato real. O teste de contrato é a rede de
  segurança: se o backend mudar, ele falha.
- Atualizar para Vite 8, React Router 8 e TypeScript 7 exige revisar APIs.

# Segurança e Privacidade

A privacidade é requisito central do Procrastinar Menos. Este documento
define as regras; cada componente deve segui-las desde o primeiro commit.

## 1. Credenciais e segredos

- Nunca versionar `.env`, chaves, tokens, certificados, dumps ou logs.
  O `.gitignore` raiz bloqueia os padrões conhecidos.
- Cada componente tem um `.env.example` com valores fictícios e comentários.
- Senhas de serviços locais são geradas por máquina (ex.:
  `python -c "import secrets; print(secrets.token_urlsafe(32))"`).
- Antes de todo commit: revisar o diff procurando segredos.
- Se um segredo vazar: revogar/rotacionar **imediatamente**; reescrever o
  histórico só com autorização explícita do proprietário.

### Rotação de credenciais locais
- `POSTGRES_PASSWORD`: com o volume já criado, alterar só o `.env` **não** muda a
  senha. É preciso aplicar `ALTER ROLE … WITH PASSWORD` via stdin dentro do container,
  atualizar o `.env` e recriar os containers. Rotacionada em 2026-11-17 (48 caracteres).
- `AI_SERVICE_TOKEN`: trocar no `.env` e recriar `backend`, `scheduler`, `queue` e `ai-service`.
- `POSTGRES_RUNTIME_PASSWORD`: trocar no `.env`, rodar `provision-db-roles.ps1` e recriar
  `backend`, `scheduler` e `queue`.

### Papéis do banco (ADR-0008, D8)
- A aplicação (backend, agendador, fila) conecta como `pm_runtime`: sem superusuário, sem
  `BYPASSRLS`, sem ser dona das tabelas, só DML. Assim a Row-Level Security vale de fato.
- `pm_app` (dono, superusuário do container) fica restrito a migrations, recriação do banco de
  testes e backups. As credenciais dele só entram no processo de
  `infrastructure/scripts/artisan-owner.ps1` e nunca no ambiente permanente dos containers.
- `check-env.ps1` falha se o papel da aplicação puder contornar a RLS.

## 2. Autenticação e autorização

- Usuários: Laravel Sanctum (sessão para o dashboard; tokens para dispositivos).
- Dispositivos: token próprio, revogável, vinculado a um usuário, com
  escopos mínimos (ex.: `events:write`, `rules:read`), expiração (90 dias),
  renovação pelo agente e reemissão pelo usuário ([ADR-0005](docs/decisions/0005-autenticacao-e-dispositivos.md)).
- Serviço-a-serviço (backend → ai-service): segredo compartilhado `AI_SERVICE_TOKEN`
  comparado em tempo constante ([ADR-0007](docs/decisions/0007-servico-de-ia-contrato-e-resiliencia.md)).
- Toda consulta a dados pessoais passa por policy/escopo de `user_id` no
  backend. O frontend nunca é fonte de autorização.
- Tabelas do NEXO (consentimentos, histórico, memórias, embeddings, sugestões, exportações)
  têm **RLS forçada**: a conexão só enxerga as linhas do usuário definido por requisição
  (`db.user`) ou por job (`DatabaseUserScope`). FKs compostas `(id, user_id)` impedem, no
  banco, ligar um registro de uma conta a outra. Testes de isolamento:
  `RowLevelSecurityTest` e os testes de cada módulo.
- Rate limiting em login, APIs de dispositivos e endpoints do NEXO.
- Auditoria de operações relevantes: login, vinculação/revogação de
  dispositivo, mudanças de consentimento, exportação/exclusão de dados,
  liberações de bloqueio.

## 3. Coleta de dados (o que pode e o que não pode)

**Permitido, com consentimento por dispositivo e por tipo:**
nome do aplicativo/processo em foco, duração de uso, domínio/URL/título
da aba ativa durante horários configurados, registros manuais, anotações
e resultados de exercícios.

**Proibido:**
- Teclas digitadas, senhas, conteúdo de formulários.
- Conteúdo de mensagens privadas, e-mails ou conversas de outros apps.
- Credenciais ou tokens de outros aplicativos.
- Capturas de tela contínuas (qualquer captura exige ADR + consentimento explícito).

O usuário pode **pausar ou desligar** o monitoramento a qualquer momento,
em cada dispositivo.

## 4. Direitos do usuário (LGPD)

Retenção e exclusão: ver [docs/security/retencao-de-dados.md](docs/security/retencao-de-dados.md)
(exclusão de conta já implementada em `DELETE /api/me`). Memória do NEXO:
[docs/security/politica-de-memoria.md](docs/security/politica-de-memoria.md) — consentimentos
independentes, consulta/edição/exclusão de memórias e conversas e exportação
(`POST /api/me/exports`) já implementados.

A aplicação deverá oferecer:
- Painel "Meus dados": o que é coletado, por quê, onde fica, por quanto tempo.
- Lista de dispositivos vinculados e permissões concedidas, com revogação.
- Exportação dos dados pessoais em formato legível.
- Exclusão de dados (por categoria, por período, ou conta inteira).
- Controle de memória do NEXO: ver, corrigir e apagar memórias e conversas.
- Política de retenção configurável (padrão conservador para eventos brutos).

Dados de um usuário nunca alimentam recomendações de outro sem
consentimento e anonimização adequados.

## 5. Rede

- PostgreSQL e Ollama escutam apenas em `127.0.0.1`.
- Comunicação de dispositivos pela LAN com TLS (certificado local) e token.
- Nenhuma porta é exposta à internet. Acesso remoto futuro somente por VPN
  ou gateway autenticado, mediante ADR e autorização do proprietário.

## 6. Logs

- Logs técnicos não contêm conteúdo de mensagens, URLs completas com
  parâmetros sensíveis, tokens ou dados pessoais desnecessários.
- Prompts e respostas do NEXO não vão para logs técnicos (backend e ai-service
  registram apenas o motivo de falhas).
- `QueryException` é registrada só com SQLSTATE e o SQL com placeholders, sem os valores
  (que poderiam conter conversas ou memórias). Jobs levam só identificadores na fila.
- Antes de gravar, o histórico passa pelo mascaramento de identificadores (CPF, CNPJ, RG,
  cartões, e-mails, telefones, senhas e tokens). Não é infalível; a política diz isso.
- O raciocínio interno do modelo (`<think>`) é removido no ai-service e de novo no
  backend; nunca chega ao usuário.

## 7. Backups

- Scripts prontos e testados: `pg_dump` cifrado com AES-256-GCM, só em dispositivo físico
  separado, sem nuvem nesta fase, com reaplicação de exclusões na restauração. Ver
  [docs/security/backup-e-restauracao.md](docs/security/backup-e-restauracao.md).
- Pendente: dispositivo separado e agendamento. BitLocker está desligado no `C:` (verificado
  em 2026-11-17); habilitar depende de autorização.

## 8. Reporte de vulnerabilidades

Projeto em desenvolvimento privado. Reporte problemas diretamente ao
proprietário do repositório; não abra issues públicas com detalhes exploráveis.

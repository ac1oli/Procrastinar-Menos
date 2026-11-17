# Proposta de arquitetura — memória do NEXO

- **Status:** proposta — aguardando aprovação ([ADR-0008](../decisions/0008-memoria-do-nexo.md))
- **Data:** 2026-11-17
- **Política:** [politica-de-memoria.md](../security/politica-de-memoria.md)

Nada deste documento está implementado. Nomes de colunas e valores numéricos são o ponto de
partida da implementação. Os valores de recuperação devem ser calibrados com testes.

## 1. Visão geral

```
                 ┌──────────────────────── Backend (Laravel) ─────────────────────────┐
 Dashboard ─────▶│ NexoController                                                      │
                 │   │ 1. ConsentGate (verifica escopos a cada requisição)             │
                 │   │ 2. ContextAssembler ── MemoryRetriever ──▶ PostgreSQL+pgvector  │
                 │   │                     ── RoutineSnapshot / GoalsSnapshot          │
                 │   │                     ── turnos recentes (histórico ou cliente)    │
                 │   │ 3. NexoGateway::reply(contexto temporário) ──────────┐          │
                 │   │ 4. ConversationRecorder (só se nexo.history)          │          │
                 │   │ 5. SuggestionValidator (só se nexo.memory.suggestions)│          │
                 │   └ Fila: EmbedMemory, ApplyNexoRetention, ExportUserData │          │
                 └───────────────────────────────────────────────────────────┼──────────┘
                                                                             ▼
                                              ai-service (sem estado) ──▶ Ollama
                                              /v1/nexo/reply  /v1/embed  /v1/nexo/suggest
```

Módulos novos no monólito (`backend/app/Modules`):
- `Consent`: escopos, histórico e verificação.
- `Conversations`: histórico e retenção.
- `Memory`: memórias, sugestões, embeddings, recuperação e revisão.

O módulo `Nexo` existente orquestra e continua sendo o único que fala com o `NexoGateway`.

## 2. Estrutura conceitual das tabelas

Todas usam UUID, `user_id` obrigatório e `timestamptz`.

### 2.1 Consentimento

**`consents`** (estado atual, uma linha por usuário e escopo)

| Coluna | Tipo | Observação |
|---|---|---|
| `user_id` | uuid FK → users, cascade | PK composta com `scope` |
| `scope` | text | `nexo.history`, `nexo.memory`, … (lista fechada em config) |
| `granted` | boolean | |
| `policy_version` | text | versão do texto aceito |
| `updated_at` | timestamptz | |

**`consent_events`** (histórico append-only)
- `id`, `user_id` (cascade), `scope`, `action` (`granted` | `revoked`), `policy_version`,
  `source` (`onboarding` | `settings` | `api`), `created_at`.
- Sem IP: a auditoria geral já registra o IP com prazo de 90 dias.

**`nexo_preferences`** (uma linha por usuário)
- `history_retention_days` (nulo significa "até eu excluir");
- `memory_review_after_days`;
- `on_revoke_default` (D7).

### 2.2 Histórico de conversas

**`conversations`**
- `id`, `user_id` (cascade), `title` (nullable; definido pela pessoa ou pelo primeiro trecho);
- `created_at`, `last_message_at`;
- `expires_at`: calculado a partir da retenção, recalculado quando ela muda.
- `UNIQUE (id, user_id)`, para servir de alvo de FK composta.

**`conversation_messages`**
- `id`, `conversation_id`, `user_id`, com FK composta → `conversations(id, user_id)` em
  cascade.
- `role` (`user` | `assistant`) e `content` (text, já mascarado conforme D5).
- `content_tsv`: `tsvector` gerado com a configuração `portuguese`, índice GIN.
- `prompt_version`, `model` e `latency_ms` só em respostas do NEXO.
- `created_at`.
- Não há coluna de raciocínio. O texto já chega sem `<think>`.

**`message_memory_usages`** (quais memórias embasaram uma resposta)
- `message_id` → `conversation_messages` (cascade), `memory_id` → `memories` (cascade),
  `user_id`.
- `score`: valor da recuperação, para depuração e calibração.
- Só existe se o histórico estiver ligado. Sem histórico, a lista volta apenas na resposta
  HTTP.

### 2.3 Memória de longo prazo

**`memories`**

| Coluna | Tipo | Observação |
|---|---|---|
| `id`, `user_id` | uuid | `UNIQUE (id, user_id)` |
| `kind` | text | `preferencia`, `objetivo_pessoal`, `conhecimento`, `padrao`, `contexto_de_vida`, `restricao` |
| `content` | text | ≤ 500 caracteres, frase autocontida |
| `content_hash` | text | sha256 do conteúdo normalizado |
| `content_tsv` | tsvector gerado | busca textual, índice GIN |
| `sensitivity` | text | `normal` \| `sensitive` (só com D4) |
| `origin` | text | `user_created` \| `suggestion_confirmed` \| `imported` \| `agent_observed` |
| `evidence_level` | text | `observada` \| `declarada` \| `consultada` \| `praticada` \| `demonstrada` |
| `confidence` | text | `alta` \| `media` \| `baixa` (regras da política, seção 3) |
| `status` | text | `active` \| `needs_review` \| `archived` |
| `pinned` | boolean | "sempre considerar" (máx. 5 por usuário, validado no backend) |
| `source_conversation_id` | uuid null | FK → conversations, **SET NULL** |
| `source_message_id` | uuid null | FK → conversation_messages, **SET NULL** |
| `created_at`, `confirmed_at`, `updated_at` | timestamptz | |
| `last_used_at`, `last_reviewed_at`, `review_due_at` | timestamptz | ciclo de revisão (D3) |

**`memory_suggestions`**
- `id`, `user_id`, `kind`, `content`, `rationale` (curta: "você mencionou que…").
- `source_conversation_id` e `source_message_id` com **CASCADE**: a sugestão não tem
  finalidade sem a origem.
- `status` (`pending`), `created_at`, `expires_at`.
- Aceitar cria a `memory` e apaga a sugestão. Recusar ou expirar apaga a sugestão. Não sobra
  registro.

**`memory_embeddings`**
- `memory_id` (PK; FK composta `(memory_id, user_id)` → `memories(id, user_id)`, cascade).
- `user_id`, `embedding_model_id` → `embedding_models`.
- `content_hash`: tem de bater com `memories.content_hash` para o vetor ser usado.
- `embedding vector(1024)`, `created_at`.

**`embedding_models`**
- `id`, `name` (`qwen3-embedding:0.6b`), `dimensions` (1024), `digest` (do Ollama), `active`,
  `created_at`.
- Trocar de modelo significa cadastrar outro, reindexar em segundo plano e então trocar o
  ativo.

### 2.4 Suporte

- **`data_exports`**: `id`, `user_id`, `status`, `path` (cifrado), `expires_at`,
  `downloaded_at`. O arquivo é apagado ao baixar ou expirar.
- **`deletion_journal`** (para restaurações): `entity_type`, `entity_id`, `user_id` (nullable),
  `deleted_at`.
  - Sem conteúdo; expira em 35 dias.
  - Na exclusão de conta guarda só `entity_type='user'` e o UUID, também por 35 dias.

## 3. Relacionamentos

```
users 1─N consents / consent_events / nexo_preferences(1─1)
users 1─N conversations 1─N conversation_messages 1─N message_memory_usages N─1 memories
users 1─N memories 1─1 memory_embeddings N─1 embedding_models
users 1─N memory_suggestions N─1 conversation_messages (origem, cascade)
memories N─0..1 conversation_messages / conversations (origem, set null)
```

| Excluir | Efeito em cascata |
|---|---|
| `users` | Tudo acima (FK cascade) + tokens/sessões/auditoria via `DeleteAccount` (ADR-0006) |
| `conversations` | mensagens → usos, sugestões pendentes; memórias: origem = NULL |
| `conversation_messages` | usos, sugestões pendentes dela; memórias: origem = NULL |
| `memories` | embedding, usos |
| Embedding desatualizado | Ignorado na busca; regenerado por job |

As FKs compostas `(…, user_id)` fazem o **banco** recusar uma mensagem de A numa conversa de
B, ou um embedding de A numa memória de B, mesmo com bug na aplicação.

## 4. PostgreSQL e pgvector

- **Extensão:** `vector` 0.8.2, já instalada e validada com HNSW na FASE 2.
- **Índice:** HNSW com `vector_cosine_ops` em `memory_embeddings.embedding` + btree em
  `(user_id, embedding_model_id)`.
- **Filtro por usuário:** toda busca leva `WHERE user_id = :uid AND embedding_model_id = :ativo`.
  - Com HNSW, filtros seletivos podem devolver menos resultados que o pedido. O pgvector 0.8
    resolve com `SET LOCAL hnsw.iterative_scan = relaxed_order`.
  - Com poucas memórias por usuário (centenas), uma varredura exata pelo índice btree é mais
    barata e o planejador tende a escolhê-la. Os dois caminhos devem ser cobertos por teste.
- **Busca textual:** `tsvector('portuguese')` gerado + GIN em `memories` e
  `conversation_messages`. É o caminho de busca do histórico (D6) e o fallback sem IA.
- **Consulta do embedding:** o `qwen3-embedding` usa instrução na consulta
  (`Instruct: …\nQuery: …`) e texto puro nos documentos. O ai-service centraliza esse formato,
  e o backend só envia `purpose: query|document`.
- **Volume esperado:** 1.000 memórias × 1024 floats × 4 bytes ≈ 4 MB por usuário ativo.
  Irrelevante para o servidor local.

## 5. Busca semântica e recuperação contextual (RAG)

### 5.1 Como o NEXO decide o que é relevante

O NEXO não carrega todas as memórias em cada mensagem. A seleção é feita no backend, em
etapas baratas e determinísticas, sem uma chamada extra ao modelo de chat:

| Etapa | Regra | Custo |
|---|---|---|
| 0. Portões | Sem `nexo.memory`, pula tudo. Sem `nexo.context.*`, a fonte não entra | Leitura de `consents` |
| 1. Fixadas | Memórias `pinned` e `active` (máx. 5) entram sempre | 1 consulta |
| 2. Candidatas | Top 20 por cosseno (embedding da pergunta) + top 20 por busca textual, só `active`/`needs_review` | 1 chamada `/v1/embed` (~dezenas de ms com o modelo carregado; a medir) + 2 consultas |
| 3. Fusão | Reciprocal Rank Fusion (k = 60) | CPU |
| 4. Ajustes | × confiança (alta 1,0 · média 0,85 · baixa 0,7); × 0,8 se `needs_review`; sensíveis só acima de um limiar mais alto | CPU |
| 5. Corte | Similaridade mínima (inicial 0,35 de cosseno, **a calibrar**); no máximo K = 6 | CPU |
| 6. Orçamento | ~600 tokens para memórias; cortar da menor relevância para a maior | CPU |

Rotina e objetivos (com consentimento) entram como recorte fixo e pequeno: os blocos de hoje
e os 3 objetivos ativos mais prioritários, em texto gerado pelo backend.

Os turnos recentes da conversa entram com teto: últimos 6 turnos e ~1.500 tokens.

**Calibração:** criar um conjunto de avaliação versionado (~50 pares "mensagem → memórias que
deveriam ser lembradas") e medir precisão e revocação ao ajustar o limiar e o K. Os números
acima são o ponto de partida, não resultados.

### 5.2 Degradação

| Falha | Comportamento |
|---|---|
| Embedding indisponível ou lento (orçamento de ~2 s) | Usa fixadas + busca textual. A resposta segue se o chat estiver de pé |
| Chat indisponível | 503 como hoje. Nada é gravado além da mensagem do usuário (se o histórico estiver ligado), marcada como "sem resposta" |
| Banco indisponível | Erro 503 genérico. Nenhum contexto é montado com dados parciais de outra origem |

### 5.3 Contexto temporário: formato e proteção

- O contrato `/v1/nexo/reply` passa a aceitar:
  - `memories[]` (`id`, `kind`, `content`);
  - `profile_context` (rotina e objetivos em texto);
  - `history[]` (turnos).
- Cada campo tem tamanho máximo e é validado nos dois lados (ADR-0007 será atualizado).
- O ai-service insere esses blocos no prompt como **dados delimitados**. O prompt de sistema
  diz que são informações sobre a pessoa, não instruções. Uma memória do tipo "ignore suas
  regras…" vira só um fato estranho, sem autoridade.
- **Sem histórico ligado:** o dashboard mantém os turnos na memória do navegador e os reenvia
  (máx. 6). O servidor não grava nada.
- Nada do contexto vai para log, cache ou fila.

### 5.4 Sugestões de memória

- Depois de responder, e só com `nexo.memory.suggestions`, o backend pode chamar
  `/v1/nexo/suggest` com a última troca.
  - Assíncrono, para não atrasar a resposta.
  - Fora de "conversa privada".
- O modelo devolve no máximo 2 candidatas em JSON (`kind`, `content`, `rationale`,
  `sensitivity_guess`).
- O backend valida: esquema, tamanho, `kind` permitido, filtro determinístico (regex de
  documentos, cartões, credenciais, e-mails e telefones; lista de termos das categorias
  sensíveis), `sensitivity_guess != normal` e duplicata de memória existente (cosseno alto).
  Falhou em qualquer item, a candidata é descartada.

## 6. Retenção

| Dado | Regra | Executor |
|---|---|---|
| Conversas | Apagadas quando `expires_at < now()` (padrão: 90 dias após a última mensagem, D2) | `nexo:apply-retention`, diário 03:20 |
| Sugestões pendentes | Apagadas após 14 dias (D10) | idem |
| Memórias | Sem expiração. `review_due_at` vencido → `needs_review` (D3) | idem |
| Exportações | Arquivo apagado em 24 h ou após download | idem + no download |
| Diário de exclusões | 35 dias | idem |
| Embeddings | Seguem a memória (cascade) | banco |

Mudar a retenção do histórico para um prazo menor aplica o novo prazo às conversas
existentes na execução seguinte. A tela avisa quantas conversas serão apagadas.

## 7. Consentimentos

- Verificação central: `ConsentGate::allows($user, 'nexo.memory')`, consultada pelo
  `ContextAssembler` e pelos gravadores. Nunca se confia em flags vindas do cliente.
- **Conceder:** registra em `consent_events` + `consents` e um evento de auditoria
  `nexo.consent.granted`, sem conteúdo.
- **Revogar:**
  1. bloqueia o uso na hora;
  2. aplica a escolha D7 (excluir agora ou manter inativo);
  3. "manter inativo" significa que nada é usado nem gravado até a pessoa religar ou excluir;
     prazos de retenção continuam correndo.
- **Dependências:** `nexo.memory.suggestions` e `nexo.memory.sensitive` exigem `nexo.memory`.
  Revogar `nexo.memory` desliga as duas.

## 8. Exclusão em cascata e embeddings

- Exclusão física pelo banco. Os embeddings não têm caminho de exclusão próprio: somem por
  `ON DELETE CASCADE` na mesma transação da memória (R8).
- Ao editar uma memória:
  1. `content_hash` muda e o vetor antigo deixa de ser usado no mesmo commit (a busca exige
     hash igual);
  2. o job `EmbedMemory` substitui o vetor;
  3. se a IA estiver fora, o job tenta de novo com espera crescente.
- **Tuplas mortas no PostgreSQL:** linhas excluídas permanecem no arquivo até o `VACUUM`. O
  autovacuum cuida disso. A política diz "excluído do sistema em uso" e trata backups à parte.
- **Exclusão de conta:** `DeleteAccount` ganha as novas tabelas (a maioria sai pela cascata de
  `users`) e o arquivo de exportação. Teste de regressão: depois da exclusão, zero linhas com o
  `user_id` em todas as tabelas `nexo`/memória.
- **Auditoria:**
  - novos eventos `nexo.consent.granted|revoked`, `nexo.conversation.deleted`,
    `nexo.memory.created|updated|deleted`, `nexo.history.cleared`, `nexo.export.requested`;
  - todos **não retidos** na exclusão de conta;
  - nunca carregam conteúdo, só IDs.

## 9. Exportação

- `POST /api/me/export` (senha + limite de 1 a cada 24 h) enfileira `ExportUserData`.
- O ZIP contém:
  - `nexo/conversations.json`, `nexo/memories.json`, `nexo/suggestions.json`,
    `nexo/consents.json`;
  - `LEIAME.md` explicando cada campo;
  - um `memorias.md` legível.
- Gravado cifrado em `storage/app/exports` (fora do `public`). Link assinado de uso único,
  24 h. Arquivo removido após o download.
- Vetores não são exportados. A FASE 4 ("Meus dados") estende o mesmo ZIP com perfil, rotinas,
  objetivos e dispositivos.

## 10. Segurança e isolamento multiusuário

| Camada | Medida |
|---|---|
| HTTP | Policies com 404 para recurso alheio; rate limits (mensagens, exportação, sugestões) |
| Aplicação | Repositórios recebem `User`, nunca `user_id` solto; `MemoryRetriever` é o único ponto de busca vetorial |
| Banco | FKs compostas `(id, user_id)`; RLS nas tabelas do NEXO (D8) |
| ai-service | Sem estado; token de serviço; não registra conteúdo; limites de tamanho por campo |
| Prompt | Memórias como dados delimitados; filtro de sensibilidade antes de sugerir |
| Logs | Conteúdo de mensagem e memória nunca vai para log; exceções sem o corpo da requisição |
| Testes | Dois usuários com memórias **idênticas**: a busca de A nunca retorna a de B (vetorial, textual e híbrida) |

**RLS (D8), se aprovado:**
- `ALTER TABLE … ENABLE ROW LEVEL SECURITY` + `FORCE`, com política
  `user_id = current_setting('app.user_id')::uuid`.
- O backend executa `SET LOCAL app.user_id` no início de cada transação autenticada.
- Rotinas de sistema (retenção, exclusão de conta) usam um papel separado com `BYPASSRLS`,
  limitado a essas tarefas.

## 11. Backup e recuperação

Hoje **não existe backup**. Proposta para o ambiente local:

| Item | Proposta |
|---|---|
| O quê | `pg_dump -Fc` do banco principal, sem os dados de `memory_embeddings` (`--exclude-table-data`), que são regenerados após restaurar |
| Quando | Diário, pelo `pm-scheduler`, em container dedicado de backup |
| Cifragem | Antes de sair do container, com chave guardada **fora** do repositório e do destino do backup |
| Rotação | 7 diários + 4 semanais (máx. ~35 dias) |
| Destino | D9 (disco ou unidade separada; nuvem só com aprovação, por custo e exposição) |
| Teste | Restauração mensal num banco temporário + verificação de contagens; resultado registrado |
| Pós-restauração | Reaplicar `deletion_journal` (exclusões posteriores ao backup) **antes** de liberar o acesso; enfileirar a reindexação de embeddings |
| Fora do backup | Modelos do Ollama (baixáveis de novo); `.env` (vai para um gerenciador de senhas) |

Objetivos sugeridos:
- RPO de 24 h (perda máxima de um dia);
- RTO de ~1 h no servidor local, a ser medido no primeiro teste de restauração.

## Decisões pendentes

A recomendação é a primeira alternativa de cada item.

### D1 — Padrão do histórico de conversas
| Alternativa | Consequências |
|---|---|
| **Escolha obrigatória no onboarding, sem pré-marcação** (recomendada) | Consentimento livre e informado (LGPD art. 8º). A pessoa decide conscientemente. Um passo a mais no onboarding |
| Desligado por padrão | Máxima privacidade; muita gente nunca liga e acha que o NEXO "esquece" |
| Ligado por padrão com aviso | Melhor continuidade, mas consentimento pré-marcado é frágil juridicamente e contraria R1/R2 no espírito |

### D2 — Retenção padrão do histórico
| Alternativa | Consequências |
|---|---|
| **90 dias, ajustável** (recomendada) | Cobre retomar assuntos recentes; limita acúmulo. Memórias importantes ficam na memória de longo prazo, não no histórico |
| 30 dias | Mais minimização; perde contexto de projetos de médio prazo |
| 365 dias / sem prazo | Mais contexto para buscas; acúmulo de dado pessoal e maior impacto num vazamento |

### D3 — Ciclo de vida da memória de longo prazo
| Alternativa | Consequências |
|---|---|
| **Sem expiração; revisão após 180 dias sem uso** (recomendada) | Nada some sem a pessoa decidir; memórias velhas pesam menos. Exige uma tela de revisão |
| Expiração automática (ex.: 1 ano sem uso) | Menos acúmulo; pode apagar algo valioso sem aviso |
| Nunca revisar | Mais simples; memórias desatualizadas induzem respostas erradas (fere R7) |

### D4 — Memórias de categoria sensível
| Alternativa | Consequências |
|---|---|
| **Só criação manual, com consentimento específico** (recomendada) | Permite, por exemplo, "tenho TDAH, prefiro tarefas curtas", útil num produto contra procrastinação, com base legal clara (art. 11, I). Mais um consentimento e um aviso na criação |
| Nunca guardar | Risco mínimo; o NEXO não pode adaptar-se a condições que a pessoa queira compartilhar |
| Permitir sugestão pelo NEXO com confirmação | Mais conveniente; o NEXO passaria a "procurar" saúde e crenças nas conversas. Fere R5 no espírito. Não recomendado |

### D5 — Dados sensíveis dentro do histórico
O histórico grava o que a pessoa escreve. Se ela digitar um CPF ou falar de saúde, isso
entraria "automaticamente".
| Alternativa | Consequências |
|---|---|
| **Mascarar identificadores (CPF, cartão, credenciais, e-mails, telefones) antes de gravar + "conversa privada"** (recomendada) | Remove o risco mais concreto. Conversas sobre saúde ficam no histórico só se a pessoa quiser (texto do consentimento explica; modo privado disponível). Regex tem falsos positivos e negativos |
| Gravar literal, com aviso no consentimento | Mais simples e fiel; credenciais coladas por engano ficariam guardadas |
| Classificar cada mensagem com o modelo e não gravar as sensíveis | Mais proteção aparente; caro (GPU), lento, impreciso no 8B e quebra o histórico |

### D6 — O que recebe embedding
| Alternativa | Consequências |
|---|---|
| **Só memórias, agora; histórico com busca textual** (recomendada) | Menos dado derivado; implementação menor; RAG focado no que foi autorizado. Buscar "aquela conversa sobre X" depende de palavras, não de significado |
| Memórias + cada mensagem | Busca semântica no histórico; muitos vetores, mais GPU durante o uso e mais dado derivado a excluir |
| Memórias + resumo por conversa | Meio-termo; resumir exige chamadas ao modelo e o resumo é um novo dado derivado a governar. Pode vir depois, com ADR próprio |

### D7 — Ao revogar um consentimento
| Alternativa | Consequências |
|---|---|
| **Parar de usar e perguntar: "excluir agora" (pré-selecionado) ou "manter inativo"** (recomendada) | A pessoa decide com clareza; o caminho padrão minimiza dados |
| Excluir sempre | Simples e rigoroso; quem só quer pausar perde tudo |
| Manter sempre inativo | Fácil religar; dado guardado sem uso, contra a minimização |

### D8 — Row-Level Security no PostgreSQL
| Alternativa | Consequências |
|---|---|
| **Adotar já nas tabelas do NEXO** (recomendada) | Segunda barreira contra vazamento entre contas, no tipo de dado mais íntimo do sistema. Exige `SET LOCAL` por transação, um papel separado para tarefas de sistema e testes específicos. Mais fácil começar com RLS do que adaptar depois |
| Adiar para a FASE 6 | Implementação mais rápida agora; o isolamento depende só da aplicação + FKs compostas; retrofit mais trabalhoso |

### D9 — Criptografia em repouso e destino do backup
| Alternativa | Consequências |
|---|---|
| **BitLocker no disco do servidor + backup cifrado em disco/unidade separada; nuvem só na FASE 6** (recomendada) | Protege contra roubo do disco; sem custo; não protege contra quem já tem acesso ao Windows ligado. O estado do BitLocker **não foi verificado** e precisa ser confirmado |
| Criptografia na aplicação (colunas de conteúdo) | Protege inclusive contra cópia do banco; impede a busca textual no banco e não se aplica aos vetores; gestão de chaves mais complexa |
| Backup em nuvem já | Proteção contra perda do equipamento; custo, conta externa e exposição dos dados (exige aprovação) |

### D10 — Fluxo das sugestões de memória
| Alternativa | Consequências |
|---|---|
| **Aviso discreto na conversa + caixa de sugestões; expira em 14 dias** (recomendada) | Confirmação no momento certo sem interromper; pendências não se acumulam |
| Só na caixa de sugestões | Menos interrupção; a pessoa pode nunca revisar |
| Perguntar na própria resposta do NEXO | Mais natural; mistura conversa com gestão de dados e polui o histórico |

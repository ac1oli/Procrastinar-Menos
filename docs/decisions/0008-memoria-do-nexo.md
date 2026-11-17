# ADR-0008 — Memória do NEXO: histórico, memória de longo prazo e contexto temporário

- **Status:** aceita — aprovada pelo proprietário com os ajustes da seção 7
- **Data:** 2026-11-17
- **Relacionados:** [ADR-0003](0003-nexo-ai-gateway.md) (memória fora do modelo),
  [ADR-0006](0006-retencao-e-exclusao-de-conta.md) (retenção e exclusão),
  [ADR-0007](0007-servico-de-ia-contrato-e-resiliencia.md) (contrato com o ai-service)
- **Documentos desta decisão:**
  [política de memória](../security/politica-de-memoria.md) ·
  [proposta de arquitetura](../architecture/memoria-do-nexo.md)

## 1. Contexto

O NEXO responde hoje sem lembrar de nada: cada mensagem chega ao modelo só com o fuso e a
hora local. Para ajudar de verdade (lembrar preferências, objetivos, o que a pessoa já sabe,
padrões que ela reconhece), ele precisa de memória. Memória sobre uma pessoa é dado pessoal,
às vezes sensível, e a proposta do produto depende de confiança.

O proprietário estabeleceu as regras abaixo, que esta decisão trata como requisitos:

| # | Requisito |
|---|---|
| R1 | Memória de longo prazo exige autorização explícita. |
| R2 | Histórico de conversas e memória de longo prazo têm controles independentes. |
| R3 | O usuário consulta, edita e exclui suas memórias. |
| R4 | O NEXO pode sugerir memórias; informações inferidas aguardam confirmação. |
| R5 | Informações sensíveis não são armazenadas automaticamente. |
| R6 | Toda memória tem origem, data de criação e nível de confiança. |
| R7 | Memórias desatualizadas podem ser revisadas ou descartadas. |
| R8 | Embeddings seguem exatamente as regras de privacidade e exclusão do registro original. |
| R9 | Excluir uma conversa trata os derivados sem outra finalidade autorizada. |
| R10 | Nenhum usuário consulta memórias ou conversas de outra conta. |
| R11 | O usuário controla retenção, exportação e exclusão. |
| R12 | Rotina, objetivos e outros dados pessoais só entram no NEXO com autorização para esse uso. |
| R13 | A indisponibilidade da IA não compromete dados já armazenados. |

## 2. Decisão

### 2.1 Três componentes separados

| Componente | O que é | Persistido? | Autorização | Onde vive |
|---|---|---|---|---|
| **Histórico de conversas** | Mensagens trocadas entre a pessoa e o NEXO, agrupadas em conversas | Sim, só com o consentimento `nexo.history` | Consentimento próprio, revogável | Tabelas `conversations` e `conversation_messages` |
| **Memória de longo prazo** | Afirmações curtas e autorizadas sobre a pessoa (preferências, objetivos, conhecimentos, padrões, restrições) | Sim, só com o consentimento `nexo.memory` e, se inferida, após confirmação | Consentimento próprio + confirmação item a item | Tabelas `memories`, `memory_embeddings`, `memory_suggestions` |
| **Contexto temporário** | Tudo que é montado para responder uma mensagem: turnos recentes, memórias recuperadas, trecho autorizado de rotina e objetivos, data e hora | **Nunca** | Cada parte depende do consentimento da sua fonte | Memória do processo durante a requisição |

Os três são independentes:
- histórico ligado e memória desligada é válido, e vice-versa;
- excluir uma conversa não apaga memórias que a pessoa confirmou, porque a memória confirmada
  tem finalidade própria;
- o contexto temporário nunca é gravado em banco, cache, fila ou log.

### 2.2 Consentimentos independentes e revogáveis (R1, R2, R12)

Cada finalidade tem um escopo de consentimento próprio, concedido e revogado
separadamente, com registro histórico (quem, quando, versão do texto apresentado):

| Escopo | Permite |
|---|---|
| `nexo.history` | Guardar mensagens das conversas |
| `nexo.memory` | Guardar e usar memórias de longo prazo |
| `nexo.memory.suggestions` | O NEXO propor memórias a partir das conversas (exige `nexo.memory`) |
| `nexo.memory.sensitive` | Guardar memórias de categoria sensível criadas pela própria pessoa (ver D4) |
| `nexo.context.routine` | Enviar ao NEXO trechos da rotina |
| `nexo.context.goals` | Enviar ao NEXO os objetivos |
| `nexo.context.activity` | Enviar dados de atividade dos agentes (FASE 5; nasce desligado) |

Regras:
- Tudo começa **desligado**. Nenhuma caixa vem marcada.
- Consentimento é verificado no backend **a cada requisição**. A revogação vale na mensagem
  seguinte.
- Novos tipos de dado pessoal só entram no contexto do NEXO com um escopo novo.

### 2.3 Memória de longo prazo (R3–R7)

- **Entrada:** só por três caminhos.
  1. A pessoa cria a memória.
  2. A pessoa confirma uma sugestão do NEXO.
  3. A pessoa importa memórias (futuro).
  Não existe gravação automática de memória.
- **Sugestões:** o ai-service pode propor candidatas. O backend valida cada uma: esquema,
  tamanho, tipo permitido, filtro de sensibilidade. As aprovadas ficam pendentes em
  `memory_suggestions` até a pessoa aceitar, editar e aceitar, ou recusar. Pendências
  expiram (D10).
- **Metadados obrigatórios em cada memória:**
  - origem (`user_created`, `suggestion_confirmed`, `imported`, `agent_observed` futuro);
  - nível de evidência do projeto (observada, declarada, consultada, praticada, demonstrada);
  - confiança (`alta`, `media`, `baixa`), por regra determinística e não pela autoavaliação
    do modelo;
  - data de criação e de confirmação;
  - conversa e mensagem de origem, quando houver;
  - último uso e última revisão.
- **Sensíveis (R5):** nunca são sugeridas pelo NEXO nem gravadas automaticamente.
  - Identificadores (CPF, cartão, senhas e tokens) são **sempre rejeitados**.
  - Categorias do art. 5º, II da LGPD (saúde, religião, vida sexual etc.) seguem a decisão D4.
- **Revisão (R7):** memórias sem uso ou revisão há N dias passam a `needs_review` (D3).
  - Memórias nesse estado têm peso menor na recuperação.
  - A pessoa confirma, edita, arquiva ou exclui.
  - Editar regrava o conteúdo **sem guardar a versão anterior**.

### 2.4 Embeddings (R8, R13)

- Somente **memórias** recebem embedding nesta etapa (D6). O histórico é pesquisado por
  busca textual do PostgreSQL.
- `memory_embeddings` fica em tabela própria e carrega:
  - o `user_id`;
  - chave estrangeira com `ON DELETE CASCADE` para a memória;
  - o modelo de embedding usado;
  - o hash do conteúdo que gerou o vetor.
- Um vetor só é usado se o hash bater com o conteúdo atual e o modelo for o ativo. Vetor
  desatualizado nunca é consultado e é regenerado por job.
- O embedding é gerado de forma **assíncrona** (fila). Se a IA estiver fora, a memória fica
  salva e continua utilizável por busca textual. O vetor é gerado quando a IA voltar. Nenhum
  dado é perdido ou bloqueado por falha da IA.
- O embedding da pergunta (usado na busca) é temporário e nunca é gravado.

### 2.5 Recuperação contextual (RAG)

Sem chamada extra ao modelo de chat para decidir o que lembrar. O backend monta o contexto
de forma determinística e com orçamento fixo:

1. **Portões de consentimento:** só consulta fontes autorizadas.
2. **Memórias fixadas** ("sempre considerar"): até 5, poucas palavras cada (nome preferido,
   tom).
3. **Busca híbrida nas memórias ativas** do usuário:
   - similaridade de cosseno no pgvector + busca textual em português;
   - combinação por Reciprocal Rank Fusion;
   - ajuste por confiança, nível de evidência e estado de revisão;
   - corte por limiar mínimo e no máximo K memórias (valores iniciais na proposta de
     arquitetura; **devem ser calibrados com um conjunto de avaliação**, não assumidos).
4. **Rotina e objetivos:** só o recorte útil (blocos de hoje e objetivos ativos mais
   prioritários), resumido pelo backend sem IA.
5. **Turnos recentes** da conversa atual, com teto.
6. **Orçamento de tokens** por seção. O que não cabe é descartado pela ordem de menor
   relevância.

Cada resposta informa quais memórias foram usadas (transparência: "o NEXO considerou…").

### 2.6 Exclusão (R9, R11)

- Exclusão é **física** (sem soft delete) e em cascata pelo banco.
- **Excluir mensagem:** apaga registros de uso e sugestões pendentes geradas nela. Memórias
  confirmadas perdem só o vínculo com a origem.
- **Excluir conversa:** apaga mensagens, sugestões pendentes e registros de uso. A interface
  oferece também excluir as memórias que nasceram dela, listando-as.
- **Excluir memória:** apaga o embedding e os registros de uso.
- **Revogar consentimento:** para de usar na hora. O destino dos dados já guardados segue a
  decisão D7.
- **Excluir a conta:** `DeleteAccount` passa a cobrir as novas tabelas. Nenhum evento `nexo.*`
  entra na lista de auditoria retida.
- Uma rotina diária aplica os prazos de retenção (histórico, sugestões, revisão de memórias).

### 2.7 Isolamento multiusuário (R10)

- `user_id` em **todas** as tabelas, inclusive embeddings e registros de uso.
- Chaves estrangeiras compostas `(id, user_id)` impedem, no banco, uma mensagem ou memória
  apontar para conversa de outro usuário.
- Policies com 404 para recurso alheio, como no restante do backend.
- A busca vetorial só existe dentro de uma classe que recebe o `User` autenticado. Não há
  consulta de vetores sem filtro de usuário.
- Defesa em profundidade com Row-Level Security do PostgreSQL: decisão D8.
- O ai-service continua **sem estado**: recebe só o contexto montado e não grava nem registra
  conteúdo em log.

### 2.8 Exportação e backup (R11, R13)

- **Exportação:** arquivo ZIP com JSON e uma versão legível. Inclui conversas, mensagens,
  memórias com todos os metadados, sugestões pendentes e o histórico de consentimentos.
  - Embeddings não são exportados: são derivados e específicos do modelo.
  - Geração assíncrona, link de uso único que expira em 24 h, reconfirmação de senha.
- **Backup:** `pg_dump` diário cifrado, rotação de 35 dias, teste de restauração periódico.
  - Os dados de `memory_embeddings` ficam fora do dump e são regenerados após restaurar.
  - Um diário de exclusões (só identificadores, sem conteúdo, com a mesma janela de 35 dias)
    é reaplicado após restaurar, para que nada excluído "volte".
  - O destino do backup é a decisão D9.

## 3. Alternativas avaliadas

- **Uma tabela única de "memória" para tudo (mensagens + fatos):** mistura finalidades com
  consentimentos diferentes e impede excluir o histórico preservando memórias. Rejeitado.
- **Memória automática (o modelo grava o que achar relevante):** fere R4 e R5 e o modelo
  local de 8B erra classificação com frequência. Rejeitado.
- **Framework de agentes com memória própria (ex.: memória no ai-service):** torna o ai-service
  stateful, duplica a fonte da verdade e dificulta exclusão e isolamento. Contraria o ADR-0003.
  Rejeitado.
- **Banco vetorial separado (Qdrant, Chroma):** mais um serviço para proteger, fazer backup e
  manter consistente na exclusão. O pgvector atende o volume previsto e mantém a exclusão
  atômica na mesma transação. Rejeitado.
- **Pedir ao modelo de chat que escolha as memórias relevantes:** soma uma chamada de ~segundos
  em GPU de 8 GB a cada mensagem e não é determinístico. Rejeitado em favor da busca híbrida
  com orçamento.
- **Soft delete:** mantém dado que a pessoa pediu para apagar. Rejeitado.
- **Guardar versões anteriores de memórias editadas:** retém o que a pessoa corrigiu ou quis
  mudar. Rejeitado. A auditoria registra só que houve edição, sem conteúdo.

## 4. Consequências

**Positivas**
- Cada finalidade pode ser ligada, desligada e apagada sozinha.
- Exclusão e isolamento garantidos pelo banco (cascatas e chaves compostas), não só pelo código.
- A IA fora do ar não perde nem trava dados. A memória continua editável e pesquisável por
  texto.
- Trocar o modelo de embedding é uma reindexação em segundo plano.

**Negativas / custos**
- Mais tabelas, um worker de fila (`pm-queue`) e uma rotina diária a mais.
- A busca semântica não cobre o histórico nesta etapa (só busca textual).
- Sugestões de memória dependem da qualidade do `qwen3:8b`. A validação no backend descarta
  muitas delas, de propósito.
- O contrato backend ↔ ai-service muda: o ADR-0007 deve ser atualizado na implementação, com
  testes dos dois lados.

**Passa a ser necessário**
- Classificar os novos eventos de auditoria `nexo.*` em `config/retention.php`.
- Conjunto de avaliação (perguntas + memórias esperadas) para calibrar limiar e K da
  recuperação.
- Testes de isolamento específicos para busca vetorial (dois usuários com conteúdo idêntico).

## 5. Plano de implementação (após aprovação)

Branch `feat/nexo-memoria`, em commits pequenos, cada um com testes:

1. Consentimentos (`consents` + histórico), API e verificação no gateway.
2. Histórico de conversas: tabelas, API de listar, consultar, excluir e configurar retenção,
   rotina de retenção.
3. Memórias: tabelas, CRUD, revisão, filtro de sensibilidade, worker de fila e embeddings.
4. Recuperação híbrida + montagem do contexto + atualização do contrato (ADR-0007) no
   ai-service.
5. Sugestões de memória (ai-service propõe, backend valida, pessoa confirma).
6. Exclusão de conta estendida, exportação e diário de exclusões. Backup na sequência,
   conforme D9.

## 6. Decisões do proprietário

Todas as recomendações foram aprovadas (ajustes na seção 7). Consequências de cada alternativa na
[proposta de arquitetura](../architecture/memoria-do-nexo.md#decisões-aprovadas).

| # | Decisão | Decisão aprovada |
|---|---|---|
| D1 | Padrão do histórico de conversas | Escolha obrigatória no onboarding, sem opção pré-marcada |
| D2 | Retenção padrão do histórico | 90 dias, ajustável (30 / 90 / 365 / até eu excluir) |
| D3 | Ciclo de vida da memória de longo prazo | Sem expiração automática; revisão sugerida após 180 dias sem uso |
| D4 | Memórias de categoria sensível (LGPD art. 11) | Só criadas manualmente pela pessoa, com consentimento `nexo.memory.sensitive` |
| D5 | Dados sensíveis dentro do histórico | Mascarar identificadores antes de gravar + "conversa privada" que não grava nada |
| D6 | O que recebe embedding | Só memórias, agora; histórico com busca textual |
| D7 | O que acontece ao revogar um consentimento | Parar de usar na hora e perguntar: excluir agora (padrão) ou manter inativo |
| D8 | Row-Level Security no PostgreSQL | Adotar já nas tabelas do NEXO |
| D9 | Criptografia e destino do backup | BitLocker no disco + backup cifrado em disco/unidade separada; nuvem só na FASE 6 |
| D10 | Fluxo das sugestões de memória | Aviso discreto na conversa + caixa de sugestões; pendência expira em 14 dias |

## 7. Ajustes aprovados pelo proprietário (2026-11-17)

- **D1:** a autorização do histórico é pedida no primeiro acesso, sem opção pré-marcada.
- **D5, reforços:**
  - mascaramento **antes** da persistência;
  - conversas privadas não são armazenadas;
  - conteúdo sensível não vai para logs, erros ou ferramentas de diagnóstico;
  - a detecção automática **não** é tratada como proteção infalível (a política e a interface
    dizem isso);
  - dados derivados seguem as mesmas regras.
- **D7:** a exclusão vem pré-selecionada, mas só ocorre com confirmação explícita do usuário.
- **D8:** RLS aplicada pela própria conexão da aplicação, que não pode ter `BYPASSRLS` nem ser
  superusuário ou dona das tabelas. Tarefas de sistema não usam um papel com bypass: definem
  explicitamente o usuário-alvo da operação. Testes de isolamento obrigatórios.
- **D9:** verificar o BitLocker antes. Se estiver desligado, apresentar o diagnóstico e pedir
  autorização antes de habilitar. Backup cifrado em dispositivo físico separado, sem nuvem
  nesta fase, com restauração, recuperação e reaplicação de exclusões documentadas.
- **Geral:** nenhuma alteração destrutiva no banco principal sem autorização; testes sem
  dados pessoais reais.

## 8. Implementação (2026-11-18) e diferenças em relação ao plano

Implementado na branch `feat/nexo-memoria` nas seis etapas do §5. Diferenças:

- **Papel de execução do banco.** A aplicação conectava como `pm_app`, superusuário com
  `BYPASSRLS`, o que anularia a RLS. Foi criado `pm_runtime` (sem superusuário, sem
  `BYPASSRLS`, não dono das tabelas, só DML). Migrations e testes usam o dono só via
  `artisan-owner.ps1`. Tarefas de sistema processam um usuário por vez com
  `DatabaseUserScope`; não existe papel com bypass.
- **Sugestões exigem o histórico** (`nexo.memory.suggestions` requer `nexo.memory` e
  `nexo.history`). Assim o job da fila leva só identificadores e lê o texto já mascarado do
  histórico. Gerar sugestões sem histórico exigiria pôr o conteúdo da conversa na fila. Memória
  e histórico continuam independentes entre si.
- **Falha da IA não grava nada** (nem a mensagem da pessoa, que fica no cliente para nova
  tentativa). A proposta de arquitetura previa gravar a mensagem "sem resposta".
- **Dado sensível na recuperação** só entra com similaridade vetorial ≥ 0,5. Coincidência de
  palavras não basta.
- **`review_due_at`** é calculado (última atividade + 180 dias), não armazenado.
- **Diário de exclusões** preenchido por gatilho em `users`, `conversations`,
  `conversation_messages`, `memories` e `memory_suggestions`. A aplicação só insere; o expurgo é
  feito pela função `purge_deletion_journal`, que recusa janela menor que 35 dias.
- **Exportação** cifrada em repouso com a `APP_KEY`; contém só os dados do NEXO (o restante
  entra no painel "Meus dados", FASE 4).
- **Backup:** scripts e teste de restauração prontos. O agendamento aguarda um dispositivo
  físico separado (o servidor tem um único disco) e a autorização para registrar tarefas no
  Windows. BitLocker desligado; habilitar aguarda autorização.

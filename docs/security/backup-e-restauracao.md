# Backup, restauração e recuperação

- **Versão:** 1.1 — 2026-11-18
- **Decisão:** [ADR-0008](../decisions/0008-memoria-do-nexo.md) (D9)
- **Scripts:** `infrastructure/scripts/backup/`

## 1. Estado atual

- **Arquitetura aprovada pelo proprietário. Scripts prontos e testados.** O teste
  `backup-selftest.ps1` faz 24 verificações com dados sintéticos, em bancos e pastas
  temporárias próprios.
- **Nenhum backup é executado nem agendado.** Aguarda o dispositivo externo. O proprietário
  **não autorizou** tarefas agendadas nem destinos improvisados.
- **Nunca há gravação no SSD principal como alternativa.** O script recusa o disco do sistema,
  e o modo de teste só é aceito dentro da pasta temporária.
- **BitLocker desligado no `C:`.** O proprietário **não autorizou** habilitar; isso depende do
  dispositivo externo e de guardar a chave de recuperação fora do computador.

## 2. Requisitos mínimos do dispositivo

| Requisito | Mínimo | Motivo |
|---|---|---|
| Tipo | Disco físico separado (HD/SSD externo USB ou disco interno adicional) | Sobreviver à falha do SSD principal |
| Conexão | Local (USB 3 ou SATA). **Sem** rede, NAS ou nuvem nesta fase | Decisão D9 |
| Capacidade | 32 GB livres no início; recomendado 256 GB ou mais | Hoje o banco tem poucos MB; a rotação mantém até 11 dumps |
| Sistema de arquivos | NTFS ou exFAT | Arquivos acima de 4 GB no futuro (FAT32 não serve) |
| Proteção física | Guardado longe do computador quando possível | Roubo, incêndio ou pico de energia que atinja os dois |
| Criptografia própria | Opcional: os arquivos já saem cifrados | Defesa adicional (BitLocker To Go), se autorizado |

Pendrives baratos servem para testes, mas não são recomendados como único destino: a vida útil
é curta e falham sem aviso.

## 3. Formato e estrutura dos arquivos

```text
E:\pm-backups\
  pm-backup-destino.json                  marcador do destino (sem segredos)
  pm-db-20261118T023000Z.dump.enc         dump completo cifrado
  pm-journal-20261118T023000Z.csv.enc     diário de exclusões cifrado (junto do dump)
  pm-journal-20261118T033000Z.csv.enc     diário de exclusões cifrado (de hora em hora)
  pm-manifest-20261118T023000Z.json       manifesto: banco, data, SHA-256 dos arquivos
```

- **Dump:** `pg_dump -Fc` (formato custom do PostgreSQL 18) do banco principal. Vai **sem os
  dados** de `memory_embeddings`, que são regenerados depois.
- **Diário:** CSV `entidade,id,user_id,data_utc`, só com identificadores.
- **Cifragem `.enc`:**
  - cabeçalho `PMBK` + versão + nonce base + tamanho do bloco;
  - blocos AES-256-GCM de 4 MB, cada um com tag de autenticação;
  - o AAD liga índice, cabeçalho e marca de último bloco, o que detecta adulteração,
    reordenação, truncamento e chave errada.
- **Nomes:** carimbo UTC `AAAAMMDDTHHMMSSZ`, que ordena por data.
- **Retenção:** 7 dumps diários + 4 semanais (até ~35 dias); diários de exclusão por 35 dias.

## 4. Proteção da chave de criptografia

- **Uma chave** de 32 bytes aleatórios (base64), criada uma vez:
  ```powershell
  Import-Module infrastructure/scripts/backup/BackupCrypto.psm1
  New-BackupKey -Path "$env:USERPROFILE\.procrastinar-menos\backup.key"
  ```
- O arquivo recebe uma permissão (ACL) que só o usuário atual consegue ler, e fica **fora do
  repositório e fora do dispositivo de backup**. O script recusa chave dentro do destino.
- **Cópia obrigatória num gerenciador de senhas** (ou impressa e guardada em local seguro).
  Sem a chave, os backups são **irrecuperáveis**. Ninguém, nem o projeto, consegue abri-los.
- **Troca de chave:** criar uma nova, fazer um backup completo novo e, depois que ele for
  verificado, descartar a antiga. Os backups antigos só abrem com a chave antiga.
- A chave nunca vai para o Git, para logs ou para a saída dos scripts.

## 5. Rotina prevista (quando o dispositivo existir e houver autorização)

| Tarefa | Frequência | Comando |
|---|---|---|
| Preparar o destino (uma vez) | — | `backup.ps1 -Destination E:\pm-backups -InitializeDestination` |
| Backup completo | Diário | `backup.ps1 -Destination E:\pm-backups` |
| Diário de exclusões | De hora em hora | `backup.ps1 -Destination E:\pm-backups -Mode Journal` |
| Verificação de integridade | Semanal | `verify-backup.ps1 -Destination E:\pm-backups -Latest` |
| Teste de restauração | Mensal | `backup-selftest.ps1` + restauração real num banco novo |

Objetivos: RPO de 24 h para dados e 1 h para exclusões. RTO estimado em cerca de 1 h, a medir no
primeiro teste real. **O agendamento no Windows não está configurado e depende de autorização.**

## 6. Dispositivo desconectado

- `backup.ps1` **nunca cria nem escolhe um destino sozinho**:
  - **Unidade inexistente** (ex.: `E:` desconectado): sai com **código 2**, mensagem clara, e
    nada é gravado em lugar nenhum.
  - **Unidade com outra letra, ou sem o marcador** `pm-backup-destino.json` (dispositivo
    errado ou não preparado): código 2, nada gravado.
  - **Mesmo disco do sistema** (`C:`): erro. O modo de teste é recusado fora da pasta
    temporária.
- **Nenhuma perda de exclusões enquanto o dispositivo estiver fora.** O diário de exclusões
  continua no banco por 35 dias, e cada cópia do diário é completa (não incremental). Se o
  dispositivo voltar em até 35 dias, a primeira execução grava tudo o que ficou para trás.
- Durante a ausência não existe backup novo. Uma falha do SSD nesse período perde os dados
  desde o último backup existente.
- Recomendação: monitorar o código de saída das tarefas, que deve ser 0, e avisar quando o
  último backup tiver mais de 48 h (a fazer junto com o agendamento).

## 7. Verificação de integridade

`verify-backup.ps1` é somente leitura. Para cada manifesto ele:
1. confere a existência e o **SHA-256** de cada arquivo;
2. **decifra** o arquivo; a autenticação do AES-GCM acusa qualquer alteração;
3. valida a estrutura do dump com `pg_restore --list`, sem restaurar nada;
4. valida o formato das linhas do diário.

Sai com código 0 se tudo estiver íntegro e 1 se houver falha. O teste automatizado confirma que
ele aprova um backup íntegro e reprova um adulterado.

## 8. Procedimento de recuperação

`restore.ps1` **nunca sobrescreve nem apaga** um banco existente: restaura sempre num banco novo.

```powershell
pwsh infrastructure/scripts/backup/restore.ps1 `
  -BackupFile E:\pm-backups\pm-db-AAAAMMDDTHHMMSSZ.dump.enc `
  -TargetDatabase procrastinar_menos_restaurado `
  [-LiveJournalDatabase procrastinar_menos]   # se o banco antigo ainda estiver legível
```

1. **Escolher o backup:** o mais recente que passe em `verify-backup.ps1`.
2. **Rodar `restore.ps1`.** Ele decifra, cria o banco novo, roda `pg_restore` e **reaplica as
   exclusões** (seção 9).
3. **Trocar o banco em uso.** Passo manual, que exige autorização:
   - parar `pm-backend`, `pm-scheduler` e `pm-queue`;
   - renomear o banco antigo, **sem apagá-lo**, e renomear o restaurado para
     `procrastinar_menos` (ou apontar `POSTGRES_DB` para ele);
   - subir os containers.
4. **Regenerar os embeddings:** `docker exec pm-backend php artisan nexo:reindex-memories`.
5. **Conferir:** `check-env.ps1`, teste ponta a ponta e uma amostra dos dados.
6. **Registrar** data, backup usado e resultado no ROADMAP ou num diagnóstico.

**Em outro servidor:** antes, rode `provision-db-roles.ps1` (o dump referencia o papel
`pm_runtime` pelo nome) e use a mesma chave de backup.

## 9. Como uma restauração não desfaz exclusões

O problema: se alguém exclui uma memória às 10h e o backup é das 02h, restaurar esse backup
traria a memória de volta. Três mecanismos impedem isso:

1. **Diário automático no banco:** gatilhos registram em `deletion_journal` toda exclusão de
   contas, conversas, mensagens, memórias e sugestões, inclusive em cascata. Só identificadores,
   sem conteúdo. A aplicação só pode inserir; o expurgo exige 35 dias ou mais.
2. **Cópia frequente do diário para o dispositivo:** junto do dump e de hora em hora, cifrada.
3. **Reaplicação obrigatória na restauração:** `restore.ps1` lê todos os diários do dispositivo
   (e o do banco antigo, com `-LiveJournalDatabase`). Ele exclui do banco restaurado tudo o que
   foi apagado depois do backup, com margem de 5 minutos. Os gatilhos do banco restaurado
   registram essas exclusões de novo, para que backups futuros também as carreguem.

Comprovado no teste automatizado: uma conta, uma conversa (com mensagens) e uma memória
excluídas depois do backup **voltaram do arquivo e foram removidas pela reaplicação**.

**Limite:** uma exclusão feita depois da última cópia do diário volta se o banco antigo estiver
ilegível. Com a rotina prevista, isso cobre no máximo 1 h.

## 10. Outros limites

- Dados excluídos continuam nos arquivos de backup até a rotação (no máximo ~35 dias). A
  política de memória informa isso.
- O diário guarda por 35 dias os UUIDs de uma conta excluída, sem nenhum outro dado.

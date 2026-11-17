# Backup, restauração e recuperação

- **Versão:** 1.0 — 2026-11-18
- **Decisão:** [ADR-0008](../decisions/0008-memoria-do-nexo.md) (D9)
- **Scripts:** `infrastructure/scripts/backup/`

## 1. Estado atual

- **Scripts prontos e testados.** O teste automatizado (`backup-selftest.ps1`) roda backup,
  exclusões, restauração e reaplicação de exclusões com dados sintéticos.
- **Backups agendados ainda não existem.** Falta um dispositivo físico separado: o servidor tem
  só um disco (NVMe de 512 GB, `C:`), e o script recusa gravar nele.
- **BitLocker está desligado** no `C:` (verificado em 2026-11-17; TPM presente e pronto).
  Habilitar depende de autorização do proprietário.

## 2. O que entra no backup

| Item | Entra? | Observação |
|---|---|---|
| Banco principal (todas as tabelas) | Sim | `pg_dump -Fc`, cifrado |
| `memory_embeddings` | Só a estrutura | Derivado; regenerado com `nexo:reindex-memories` após restaurar |
| Diário de exclusões | Sim, também em arquivo próprio | Necessário para reaplicar exclusões |
| Modelos do Ollama | Não | Baixáveis de novo |
| `infrastructure/docker/.env` e a chave do backup | Não | Guardar num gerenciador de senhas |
| Arquivos de exportação | Não | Temporários (24 h) |

## 3. Cifragem e chave

- **Algoritmo:** AES-256-GCM em blocos autenticados (`BackupCrypto.psm1`). Adulteração, troca de
  chave, truncamento e remoção do último bloco são detectados (há teste para cada caso).
- **Chave:** 32 bytes aleatórios em `%USERPROFILE%\.procrastinar-menos\backup.key`, legível só pelo
  usuário. Crie uma vez:
  ```powershell
  Import-Module infrastructure/scripts/backup/BackupCrypto.psm1
  New-BackupKey -Path "$env:USERPROFILE\.procrastinar-menos\backup.key"
  ```
- **Guarde uma cópia da chave num gerenciador de senhas.** Sem ela, os backups são
  irrecuperáveis. A chave nunca fica no dispositivo de backup (o script recusa).
- O texto em claro existe só por instantes, em arquivo temporário apagado em seguida.

## 4. Rotina proposta (quando houver o dispositivo)

| Tarefa | Frequência | Comando |
|---|---|---|
| Backup completo | Diário, 02:30 | `backup.ps1 -Destination E:\pm-backups` |
| Diário de exclusões | De hora em hora | `backup.ps1 -Destination E:\pm-backups -Mode Journal` |
| Teste de restauração | Mensal | `backup-selftest.ps1` + restauração real num banco novo |

- **Retenção:** 7 diários + 4 semanais (até ~35 dias).
- **Diário de exclusões:** arquivos guardados por 35 dias. No banco, `purge_deletion_journal`
  recusa janela menor que 35 dias.
- **Agendamento:** depende do Agendador de Tarefas do Windows. Registrar as tarefas altera a
  configuração da máquina e precisa de autorização.
- **Objetivos:** RPO de 24 h para dados e de 1 h para exclusões; RTO estimado em cerca de 1 h,
  a medir no primeiro teste real.

## 5. Restauração

`restore.ps1` **nunca sobrescreve nem apaga** um banco existente: restaura sempre num banco novo.

```powershell
pwsh infrastructure/scripts/backup/restore.ps1 `
  -BackupFile E:\pm-backups\pm-db-AAAAMMDDTHHMMSSZ.dump.enc `
  -TargetDatabase procrastinar_menos_restaurado `
  [-LiveJournalDatabase procrastinar_menos]   # se o banco antigo ainda estiver legível
```

O que o script faz:
1. Decifra o arquivo. Falha se a chave estiver errada ou o arquivo estiver corrompido.
2. Cria o banco novo e roda `pg_restore`.
3. **Reaplica as exclusões** registradas depois do backup:
   - lê os arquivos de diário do dispositivo e, se informado, o diário do banco antigo;
   - exclui no banco restaurado contas, conversas, mensagens, memórias e sugestões apagadas
     depois do backup, com 5 minutos de margem;
   - os gatilhos do banco restaurado registram essas exclusões, para que backups futuros
     também as carreguem.

**Passos manuais depois do script** (exigem autorização, porque trocam o banco em uso):
1. Parar `pm-backend`, `pm-scheduler` e `pm-queue`.
2. Trocar o banco: renomear o antigo para guardá-lo (não apagar) e renomear o restaurado para
   `procrastinar_menos`, ou apontar `POSTGRES_DB` para o restaurado.
3. Subir os containers e rodar:
   ```powershell
   docker exec pm-backend php artisan nexo:reindex-memories
   ```
4. Conferir `check-env.ps1` e o smoke test.

**Restauração em outro servidor:** antes, rodar `provision-db-roles.ps1` para criar o papel
`pm_runtime`, porque o dump referencia os papéis pelo nome.

## 6. Limites conhecidos

- **Exclusões podem voltar.** Uma exclusão feita depois do último arquivo de diário volta se o
  banco antigo estiver ilegível (perda total do disco). Isso cobre no máximo 1 h com a rotina
  proposta. Se o banco antigo estiver legível, `-LiveJournalDatabase` fecha a janela.
- **Dados excluídos persistem nos backups.** Eles continuam nos arquivos até a rotação, por no
  máximo ~35 dias. A política de memória informa isso.
- **O diário guarda UUIDs da conta excluída por 35 dias,** sem nenhum outro dado.

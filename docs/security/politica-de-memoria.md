# Política de memória do NEXO

- **Versão:** 1.0 — 2026-11-17 — aprovada; vale para cada parte à medida que é implementada
- **Decisão:** [ADR-0008](../decisions/0008-memoria-do-nexo.md) (aceita)
- **Arquitetura:** [memoria-do-nexo.md](../architecture/memoria-do-nexo.md)

Esta política descreve, em linguagem direta, o que o NEXO pode lembrar, como a pessoa
controla isso e o que acontece com os dados. Os marcadores **(Dn)** remetem às decisões
aprovadas no ADR-0008.

## 1. O que o NEXO guarda

| Tipo | Exemplo | Guardado quando |
|---|---|---|
| **Histórico de conversas** | As mensagens trocadas numa conversa | O histórico está ligado e a conversa não é privada |
| **Memória de longo prazo** | "Prefere estudar de manhã", "Está aprendendo Laravel", "Trabalha em turnos à noite" | A memória está ligada **e** a pessoa criou ou confirmou o item |
| **Contexto temporário** | O que foi reunido para responder a uma mensagem | **Nunca**: existe só durante a resposta |

O NEXO **não** guarda:
- o raciocínio interno do modelo;
- o texto completo dos prompts;
- o embedding das perguntas;
- nenhum dado no serviço de IA.

Os dados **nunca** são usados para treinar ou ajustar modelos e não são enviados a
terceiros. A IA roda localmente.

## 2. Autorizações

- Cada uso tem uma chave própria, **desligada por padrão**:
  - guardar histórico;
  - memória de longo prazo;
  - sugestões de memória;
  - memórias sensíveis;
  - usar a rotina;
  - usar os objetivos;
  - usar a atividade dos agentes (FASE 5).
- Ligar ou desligar uma chave não afeta as outras. Por exemplo, dá para ter memória sem
  histórico.
- A revogação vale a partir da próxima mensagem. O que acontece com o que já foi guardado
  segue **(D7)**.
- Todo ato de conceder ou revogar fica registrado com a data e a versão do texto apresentado.
  A pessoa pode consultar esse registro.

## 3. Como uma memória nasce

1. **A pessoa cria:** escreve a memória diretamente.
2. **O NEXO sugere, a pessoa confirma:** com sugestões ligadas (exigem memória e histórico
   ligados), o NEXO pode propor algo que percebeu numa conversa guardada.
   - A sugestão fica pendente até a pessoa aceitar, editar ou recusar.
   - Pendências expiram em 14 dias **(D10)**.
   - Recusar apaga a sugestão.
3. **Nada é gravado sozinho.** Informação inferida nunca vira memória sem confirmação.

Cada memória mostra:
- de onde veio;
- quando foi criada e confirmada;
- o nível de evidência (declarada, observada, consultada, praticada, demonstrada);
- a confiança (alta, média, baixa);
- a última vez que foi usada.

Regras de confiança:

| Situação | Confiança |
|---|---|
| Criada ou editada pela pessoa | Alta |
| Sugestão aceita sem edição | Alta (a pessoa confirmou) |
| Padrão observado por agente (FASE 5) | Média ou baixa, conforme o número de observações |
| Marcada para revisão | Mantém o valor, mas pesa menos na recuperação |

## 4. Informações sensíveis

| Categoria | Tratamento |
|---|---|
| Senhas, tokens, chaves, números de cartão, CPF, RG e outros documentos | **Nunca guardados como memória**, nem a pedido. No histórico, são mascarados antes de gravar **(D5)** |
| Saúde (inclui TDAH, ansiedade, medicação), religião, opinião política, vida sexual, origem racial ou étnica, filiação sindical, genética, biometria (LGPD art. 5º, II) | Nunca sugeridos pelo NEXO e nunca gravados automaticamente. Só se a pessoa criar manualmente, com a autorização específica de memórias sensíveis **(D4)** |
| Informações sobre terceiros (nomes, contatos de outras pessoas) | Não sugeridas pelo NEXO; a pessoa pode criar manualmente |
| Localização precisa | Não guardada como memória nesta fase |

Se houver dúvida sobre a categoria de uma sugestão, ela é tratada como sensível e descartada.

**A detecção automática não é infalível.** Ela usa padrões (documentos, cartões, contatos,
credenciais) e palavras-chave (categorias sensíveis). Formatos incomuns, sinônimos ou gírias
podem escapar, e textos comuns podem ser mascarados por engano. Por isso ela é uma camada
de proteção, não uma garantia: evite escrever dados que não queira guardar e use a conversa
privada quando preferir.
A pessoa pode ativar a **conversa privada**, que não grava nada da conversa, nem sugestões
**(D5)**.

## 5. Revisão e atualização

- Memórias sem uso nem revisão há 180 dias são marcadas **"para revisar"** **(D3)**.
- O NEXO continua podendo usá-las, com menor prioridade.
- A pessoa decide se confirma, edita, arquiva ou exclui.
- Memórias arquivadas não são usadas e não aparecem na busca do NEXO, mas continuam visíveis
  e exportáveis para a pessoa.
- Editar substitui o conteúdo. Versões anteriores **não** são guardadas.

## 6. Prazos de retenção

| Dado | Prazo padrão | Ajustável pela pessoa |
|---|---|---|
| Histórico de conversas | 90 dias após a última mensagem da conversa **(D2)** | 30 / 90 / 365 dias / até excluir |
| Memória de longo prazo | Até a pessoa excluir; revisão sugerida em 180 dias **(D3)** | Sim |
| Sugestões pendentes | 14 dias | Não |
| Registro de "memórias usadas na resposta" | Mesmo prazo da mensagem a que pertence | Segue o histórico |
| Embeddings | Exatamente o do registro de origem; apagados junto | Segue a memória |
| Histórico de consentimentos | Enquanto a conta existir | Não (é prova da autorização) |
| Arquivo de exportação | 24 horas ou primeiro download | Não |
| Backups | Até 35 dias, depois rotacionados **(D9)** | Não |

## 7. Exclusão

- **Uma mensagem:** sai junto com as sugestões pendentes geradas por ela.
- **Uma conversa:** saem as mensagens, as sugestões pendentes e os registros de uso.
  - Memórias que a pessoa confirmou a partir dela **permanecem**, porque têm finalidade
    própria. Perdem só o vínculo com a conversa.
  - A tela de exclusão lista essas memórias e oferece excluí-las também.
- **Uma memória:** sai com o embedding e com os registros de uso.
- **Todo o histórico ou toda a memória:** um botão para cada, com confirmação.
- **A conta:** tudo acima é excluído. Nenhum registro do NEXO entra na auditoria retida
  ([política de retenção](retencao-de-dados.md)).
- A exclusão é definitiva no sistema em uso. Cópias em backup somem na rotação de até 35
  dias. Se um backup for restaurado, as exclusões feitas depois dele são reaplicadas.

## 8. Exportação

- A pessoa pode baixar todos os dados do NEXO num arquivo ZIP:
  - JSON para portabilidade;
  - uma versão legível;
  - conversas, memórias com metadados, sugestões pendentes e o histórico de autorizações.
- Embeddings não são incluídos: são cálculos derivados do texto, que já está no arquivo.
- A exportação exige a senha, gera um link de uso único válido por 24 h e tem limite de
  frequência.

## 9. Isolamento e segurança

- Cada registro pertence a uma única conta. A separação é garantida por regras no banco de
  dados, não só pela aplicação.
- Pedidos a dados de outra conta recebem "não encontrado".
- O serviço de IA não guarda nada e não grava o conteúdo das conversas em log.
- Logs da aplicação não contêm o conteúdo de mensagens ou memórias.
- Memórias são enviadas ao modelo como **informação sobre a pessoa**, nunca como instruções.
  Isso reduz o risco de uma memória manipular o comportamento do NEXO.

## 10. Quando a IA está fora do ar

- Conversas, memórias e autorizações continuam acessíveis, editáveis, exportáveis e
  excluíveis.
- O NEXO não responde e avisa que está indisponível.
- Memórias criadas nesse período ficam salvas e recebem o índice semântico quando a IA voltar.

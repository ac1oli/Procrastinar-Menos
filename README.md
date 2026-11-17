# Procrastinar Menos

**Sistema inteligente de foco e desenvolvimento pessoal.**

> Organize sua rotina. Desenvolva seu potencial. Conquiste seus objetivos.

O Procrastinar Menos ajuda pessoas a compreender seus hábitos, organizar
rotinas sustentáveis, reduzir distrações e desenvolver competências
pessoais e profissionais. No centro está o **NEXO**, um assistente que
entende contexto — uma aula de Python no YouTube não é o mesmo que vídeos
aleatórios, e três dias sem estudar podem ser cansaço, não falta de disciplina.

## Pilares

1. **Organização de rotina** — planejamento semanal, rotinas de dias úteis
   e fins de semana, ajustes negociados com o NEXO.
2. **Foco e distrações** — monitoramento consentido em Windows, Android e
   navegador; bloqueios contextuais que funcionam mesmo sem IA.
3. **Aprendizagem e carreira** — central de conhecimento, revisão por
   leitura ou questões, métodos de estudo experimentados e medidos,
   trilhas profissionais adaptativas.

## Estado atual

🚧 **FASE 1 concluída, FASE 2 em andamento** — ambiente e fundação
documental. **Ainda não há funcionalidades de produto implementadas.**
Veja o [ROADMAP.md](ROADMAP.md).

## Tecnologias

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.3+ · Laravel 13 (monólito modular) |
| Banco | PostgreSQL 18 · pgvector |
| IA | Python · FastAPI · Ollama (modelos locais) |
| Dashboard | React · TypeScript · Tailwind CSS · shadcn/ui |
| Agente Windows | C# · .NET |
| Android | Kotlin · Jetpack Compose |
| Extensão | TypeScript (Chrome/Edge, Manifest V3) |
| Comunicação | REST · WebSockets |
| Ambiente | Docker Desktop · WSL2 |

## Estrutura

```text
backend/            Laravel — regras de negócio e APIs
ai-service/         FastAPI — serviço de IA do NEXO
dashboard/          React — interface web
desktop-agent/      C#/.NET — agente Windows
mobile/             Kotlin — app Android
browser-extension/  TypeScript — extensão Chrome/Edge
infrastructure/     docker/, database/, scripts/
docs/               architecture/, decisions/ (ADRs), security/, guides/, diagnostics/
```

## Começando

Veja [DEVELOPMENT.md](DEVELOPMENT.md). Verificação rápida do ambiente:

```powershell
pwsh infrastructure/scripts/check-env.ps1
```

## Documentação

- [ARCHITECTURE.md](ARCHITECTURE.md) — componentes, fluxos e princípios
- [ROADMAP.md](ROADMAP.md) — fases, progresso e próximos passos
- [DEVELOPMENT.md](DEVELOPMENT.md) — ambiente, execução e testes
- [SECURITY.md](SECURITY.md) — segurança, privacidade e LGPD
- [CLAUDE.md](CLAUDE.md) — regras permanentes para o Claude Code
- [docs/decisions/](docs/decisions/) — registros de decisões arquiteturais

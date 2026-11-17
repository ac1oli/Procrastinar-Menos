# ADR-0001 — Monorepo com monólito modular + serviço de IA

- **Status:** aceita
- **Data:** 2026-11-16

## Contexto
O produto tem seis componentes (backend, serviço de IA, dashboard, agente
Windows, app Android, extensão de navegador) desenvolvidos por uma equipe
pequena. Os contratos entre eles (API, eventos de sincronização, regras de
foco) mudam com frequência no início.

## Decisão
- Um único repositório (`ac1oli/Procrastinar-Menos`), uma pasta por componente.
- Backend como **monólito modular Laravel**: módulos internos por domínio
  (Identidade, Dispositivos, Rotina, Objetivos, Foco, Conhecimento,
  Aprendizagem, Carreira, NEXO, Privacidade), com fronteiras explícitas.
- **Um único** serviço separado: `ai-service` (FastAPI), porque o
  ecossistema de IA é Python e o perfil de carga (GPU, latência longa) é
  diferente do backend.
- Testes ficam dentro de cada componente (`backend/tests`, `ai-service/tests`…),
  não em uma pasta `tests/` raiz, pois cada stack tem seu próprio runner.
  Testes ponta a ponta entre componentes, quando existirem, ficarão em `tests/e2e/`.

## Alternativas avaliadas
- **Microsserviços por domínio** — custo operacional alto, sem benefício
  para um usuário/uma máquina. Rejeitado.
- **Repositório por componente** — dificulta mudanças coordenadas de
  contrato. Rejeitado por ora; pode ser revisto na FASE 6.

## Consequências
- Mudanças de contrato entram em um único PR/commit coordenado.
- Disciplina de fronteiras entre módulos do Laravel precisa ser mantida
  (sem consultas diretas às tabelas de outro módulo).

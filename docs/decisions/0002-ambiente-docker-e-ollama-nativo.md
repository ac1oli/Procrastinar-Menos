# ADR-0002 — Infraestrutura em Docker, Ollama nativo no Windows

- **Status:** aceita
- **Data:** 2026-11-16

## Contexto
A máquina principal é Windows 11 Pro com GPU **AMD Radeon RX 5700 XT**
(RDNA1, gfx1010, 8 GB). Não havia PHP, Composer, PostgreSQL, Docker nem
WSL2 instalados. O pgvector não tem binários oficiais para Windows nativo
(exige compilar com MSVC).

## Decisão
- **WSL2 + Docker Desktop** para PostgreSQL 18 + pgvector (imagem oficial
  `pgvector/pgvector:0.8.2-pg18`) e, na FASE 3, para o runtime PHP/Laravel.
  Nada de PHP/Composer instalado globalmente no Windows.
- **Ollama nativo no Windows**, não em container: o Docker Desktop no
  Windows não repassa GPUs AMD a containers. O Ollama nativo usa a GPU
  via backend **Vulkan** (o ROCm para Windows não suporta gfx1010).
- Todas as portas publicadas apenas em `127.0.0.1`.
- Containers acessam o Ollama por `http://host.docker.internal:11434`.

## Alternativas avaliadas
- **Tudo nativo no Windows** — pgvector exigiria build manual; versões
  globais de PHP poluiriam a máquina. Rejeitado.
- **Ollama em container** — rodaria apenas em CPU (sem GPU AMD). Rejeitado.

## Consequências
- Exigiu habilitar `VirtualMachinePlatform` e WSL (reinicialização necessária).
- O Docker Desktop é gratuito para uso pessoal/pequenas empresas; em uso
  comercial futuro, revisar a licença (FASE 6).
- O Ollama, por padrão, escuta só em loopback. Expô-lo à LAN para outros
  dispositivos **não** é necessário: os dispositivos falam com o backend,
  e o backend fala com o Ollama.

<?php

use Illuminate\Support\Facades\Schedule;

// Política de retenção (docs/security/retencao-de-dados.md).
Schedule::command('audit:apply-retention')->dailyAt('03:00');

// Remove tokens de dispositivo expirados há mais de 24 h (ADR-0005).
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:10');

// Retenção do NEXO: histórico, sugestões e revisão de memórias (ADR-0008 §6).
Schedule::command('nexo:apply-retention')->dailyAt('03:20');

// Embeddings faltantes (IA fora do ar, troca de modelo, restauração de backup).
Schedule::command('nexo:reindex-memories')->hourly();

<?php

use Illuminate\Support\Facades\Schedule;

// Política de retenção (docs/security/retencao-de-dados.md).
Schedule::command('audit:apply-retention')->dailyAt('03:00');

// Remove tokens de dispositivo expirados há mais de 24 h (ADR-0005).
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:10');

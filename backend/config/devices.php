<?php

return [

    /*
    | Validade dos tokens de dispositivo, em dias (ADR-0005). 0 = sem expiração.
    | Agentes devem renovar via POST /api/device/token/rotate dentro da janela
    | de renovação; tokens expirados exigem nova emissão pelo usuário.
    */
    'token_ttl_days' => (int) env('DEVICE_TOKEN_TTL_DAYS', 90),

    // Dias antes da expiração em que o heartbeat sinaliza renewal_due = true.
    'token_renewal_window_days' => (int) env('DEVICE_TOKEN_RENEWAL_WINDOW_DAYS', 15),

];

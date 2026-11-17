<?php

namespace App\Modules\Devices\Data;

use Carbon\CarbonInterface;

/** Token recém-emitido. O texto em claro existe apenas neste objeto. */
final readonly class IssuedDeviceToken
{
    public function __construct(
        public string $plainText,
        public ?CarbonInterface $expiresAt,
    ) {}

    /** @return array{token: string, token_expires_at: string|null} */
    public function toArray(): array
    {
        return [
            'token' => $this->plainText,
            'token_expires_at' => $this->expiresAt?->toIso8601String(),
        ];
    }
}

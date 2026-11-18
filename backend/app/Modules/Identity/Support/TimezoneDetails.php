<?php

namespace App\Modules\Identity\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Situação de um fuso IANA agora, calculada pela base de fusos do PHP:
 * deslocamento atual, se está em horário de verão e a próxima mudança de horário
 * (dentro de 1 ano). A interface só mostra o resultado; a regra fica aqui.
 */
final class TimezoneDetails
{
    /**
     * @return array{
     *     offset: string,
     *     abbreviation: string,
     *     is_dst: bool,
     *     local_time: string,
     *     next_transition: array{at: string, offset: string, is_dst: bool}|null
     * }
     */
    public static function for(string $timezone, ?CarbonImmutable $now = null): array
    {
        $zone = new DateTimeZone($timezone);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($zone);

        $next = null;
        $transitions = $zone->getTransitions($now->getTimestamp(), $now->addYear()->getTimestamp());
        // O primeiro item é o estado vigente no início do intervalo; mudanças vêm depois.
        foreach (array_slice($transitions ?: [], 1) as $transition) {
            if ($transition['offset'] !== $now->getOffset()) {
                $next = [
                    'at' => CarbonImmutable::createFromTimestamp($transition['ts'], $zone)->toIso8601String(),
                    'offset' => self::formatOffset($transition['offset']),
                    'is_dst' => (bool) $transition['isdst'],
                ];
                break;
            }
        }

        return [
            'offset' => self::formatOffset($now->getOffset()),
            'abbreviation' => $now->format('T'),
            'is_dst' => (bool) $now->format('I'),
            'local_time' => $now->toIso8601String(),
            'next_transition' => $next,
        ];
    }

    private static function formatOffset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}

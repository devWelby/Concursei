<?php

declare(strict_types=1);

namespace Concursei\Core;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Fonte única de tempo (UTC), compatível com PSR-20 (usada também pelo TOTP).
 * Testes usam setNow() para congelar o relógio.
 */
class Clock implements ClockInterface
{
    private ?DateTimeImmutable $frozen = null;

    public function now(): DateTimeImmutable
    {
        return $this->frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function setNow(?DateTimeImmutable $now): void
    {
        $this->frozen = $now?->setTimezone(new DateTimeZone('UTC'));
    }

    /** Formato DATETIME do MySQL em UTC. */
    public function nowDb(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function plusSecondsDb(int $seconds): string
    {
        return $this->now()->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }
}

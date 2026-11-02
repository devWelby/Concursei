<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use DateTimeImmutable;
use DateTimeZone;

/**
 * "Hoje" no fuso de exibição (app.display_timezone). Prazos de inscrição são
 * divulgados no horário de Brasília: perto da meia-noite UTC o dia ainda é o anterior.
 */
final class LocalDate
{
    public function __construct(private Clock $clock, private Config $config)
    {
    }

    public function today(): DateTimeImmutable
    {
        $tz = new DateTimeZone($this->config->string('app.display_timezone', 'America/Sao_Paulo'));
        $local = $this->clock->now()->setTimezone($tz);
        return new DateTimeImmutable($local->format('Y-m-d'), $tz);
    }
}

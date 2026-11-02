<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use DateTimeZone;
use OverflowException;

/**
 * Teto de uso de IA por dia e por mês (fuso de exibição), em tokens e chamadas.
 * reserve() faz UPDATE condicional ao teto nas duas linhas dentro de uma transação: chamadas
 * concorrentes não furam o limite. settle() troca a reserva pelo consumo informado pelo provedor.
 */
class AiBudget
{
    public function __construct(private Database $db, private Clock $clock, private Config $config)
    {
    }

    /** @return array{day:string,month:string,tokens:int}|null reserva feita, ou null se o teto não comporta */
    public function reserve(int $tokens): ?array
    {
        [$day, $month] = $this->periods();
        $now = $this->clock->nowDb();
        try {
            $this->db->transaction(function () use ($day, $month, $tokens, $now): void {
                $this->db->run('INSERT IGNORE INTO ai_budget (period, updated_at) VALUES (?, ?), (?, ?)', [$day, $now, $month, $now]);
                $okMonth = $this->db->affected(
                    'UPDATE ai_budget SET reserved_tokens = reserved_tokens + ?, updated_at = ? WHERE period = ? AND reserved_tokens + used_tokens + ? <= ?',
                    [$tokens, $now, $month, $tokens, $this->config->int('ai.monthly_token_limit', 10000000)]
                );
                $okDay = $okMonth === 1 && $this->db->affected(
                    'UPDATE ai_budget SET reserved_tokens = reserved_tokens + ?, calls = calls + 1, updated_at = ?
                     WHERE period = ? AND reserved_tokens + used_tokens + ? <= ? AND calls < ?',
                    [$tokens, $now, $day, $tokens, $this->config->int('ai.daily_token_limit', 1000000), $this->config->int('ai.daily_call_limit', 200)]
                ) === 1;
                if (!$okDay) {
                    throw new OverflowException('teto'); // desfaz a reserva do mês
                }
            });
        } catch (OverflowException) {
            return null;
        }
        return ['day' => $day, 'month' => $month, 'tokens' => $tokens];
    }

    /** @param array{day:string,month:string,tokens:int} $reservation */
    public function settle(array $reservation, int $usedTokens): void
    {
        $this->db->run(
            'UPDATE ai_budget SET reserved_tokens = IF(reserved_tokens >= ?, reserved_tokens - ?, 0), used_tokens = used_tokens + ?, updated_at = ?
             WHERE period IN (?, ?)',
            [$reservation['tokens'], $reservation['tokens'], max(0, $usedTokens), $this->clock->nowDb(), $reservation['day'], $reservation['month']]
        );
    }

    /** Segundos até o próximo dia local (quando o teto diário renova). */
    public function secondsUntilReset(): int
    {
        $local = $this->clock->now()->setTimezone($this->tz());
        return max(60, $local->modify('tomorrow')->getTimestamp() - $local->getTimestamp());
    }

    /** @return array{day:array<string,int>,month:array<string,int>,limits:array<string,int>} */
    public function status(): array
    {
        [$day, $month] = $this->periods();
        $row = fn (string $p): array => array_map('intval', $this->db->one('SELECT reserved_tokens, used_tokens, calls FROM ai_budget WHERE period = ?', [$p]) ?? ['reserved_tokens' => 0, 'used_tokens' => 0, 'calls' => 0]);
        return [
            'day' => $row($day),
            'month' => $row($month),
            'limits' => [
                'day_tokens' => $this->config->int('ai.daily_token_limit', 1000000),
                'month_tokens' => $this->config->int('ai.monthly_token_limit', 10000000),
                'day_calls' => $this->config->int('ai.daily_call_limit', 200),
            ],
        ];
    }

    /** @return array{0:string,1:string} */
    private function periods(): array
    {
        $local = $this->clock->now()->setTimezone($this->tz());
        return ['d:' . $local->format('Y-m-d'), 'm:' . $local->format('Y-m')];
    }

    private function tz(): DateTimeZone
    {
        return new DateTimeZone($this->config->string('app.display_timezone', 'America/Sao_Paulo'));
    }
}

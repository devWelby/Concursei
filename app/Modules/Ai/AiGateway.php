<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

use Concursei\Core\Clock;
use Concursei\Core\Config;
use Concursei\Core\Database\Database;
use Concursei\Core\Logging\Logger;

/**
 * Porta única para a IA (D-018): configuração → circuit breaker → reserva de orçamento → chamada →
 * acerto do consumo → registro (sem conteúdo). Toda falha sai como AiError com tipo estável;
 * a fila decide se reagenda. Nada aqui publica ou altera dados do catálogo.
 */
class AiGateway
{
    public function __construct(
        private AiProvider $provider,
        private AiBudget $budget,
        private Database $db,
        private Clock $clock,
        private Config $config,
        private Logger $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->provider->isConfigured();
    }

    public function call(AiRequest $request, ?int $userId = null): AiResponse
    {
        if (!$this->provider->isConfigured()) {
            throw new AiError('config', false);
        }
        $openFor = $this->circuitOpenSeconds();
        if ($openFor > 0) {
            throw new AiError('circuit_open', true, $openFor);
        }
        $estimate = $request->estimatedTokens();
        $reservation = $this->budget->reserve($estimate);
        if ($reservation === null) {
            $this->log($request, $estimate, 'failed', 'budget', null, null);
            throw new AiError('budget', true, $this->budget->secondsUntilReset());
        }

        $started = microtime(true);
        try {
            $response = $this->provider->generate($request);
        } catch (AiError $e) {
            // Resultado incerto (ex.: tempo esgotado) é contado pela estimativa: postura conservadora.
            $this->budget->settle($reservation, $e->uncertain ? $estimate : 0);
            $this->log($request, $estimate, 'failed', $e->kind, null, (int) ((microtime(true) - $started) * 1000), $userId);
            $e->countsForCircuit() ? $this->circuitFailure($e->kind) : $this->circuitSuccess();
            $this->logger->warning('Chamada de IA falhou', ['purpose' => $request->purpose, 'kind' => $e->kind]);
            throw $e;
        }
        $this->budget->settle($reservation, $response->totalTokens ?? $estimate);
        $this->log($request, $estimate, 'ok', null, $response, (int) ((microtime(true) - $started) * 1000), $userId);
        $this->circuitSuccess();
        return $response;
    }

    /** @return array<string,mixed> estado para o painel (sem segredos) */
    public function status(): array
    {
        return [
            'configured' => $this->provider->isConfigured(),
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
            'budget' => $this->budget->status(),
            'circuit' => $this->db->one('SELECT consecutive_failures, open_until, last_failure_at, last_error_kind FROM ai_circuit WHERE provider = ?', [$this->provider->name()]),
            'recent' => $this->db->all('SELECT purpose, model, status, error_kind, finish_reason, estimated_tokens, total_tokens, latency_ms, created_at FROM ai_calls ORDER BY id DESC LIMIT 20'),
        ];
    }

    private function circuitOpenSeconds(): int
    {
        $until = $this->db->scalar('SELECT open_until FROM ai_circuit WHERE provider = ?', [$this->provider->name()]);
        if (!is_string($until)) {
            return 0;
        }
        return max(0, strtotime($until . ' UTC') - $this->clock->now()->getTimestamp());
    }

    private function circuitFailure(string $kind): void
    {
        $now = $this->clock->nowDb();
        $this->db->run(
            'INSERT INTO ai_circuit (provider, consecutive_failures, last_failure_at, last_error_kind) VALUES (?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE consecutive_failures = consecutive_failures + 1, last_failure_at = VALUES(last_failure_at), last_error_kind = VALUES(last_error_kind)',
            [$this->provider->name(), $now, $kind]
        );
        $this->db->run(
            'UPDATE ai_circuit SET open_until = ? WHERE provider = ? AND consecutive_failures >= ?',
            [$this->clock->plusSecondsDb($this->config->int('ai.circuit_cooldown_seconds', 900)), $this->provider->name(), $this->config->int('ai.circuit_failures', 5)]
        );
    }

    private function circuitSuccess(): void
    {
        $this->db->run('UPDATE ai_circuit SET consecutive_failures = 0, open_until = NULL WHERE provider = ?', [$this->provider->name()]);
    }

    private function log(AiRequest $request, int $estimate, string $status, ?string $kind, ?AiResponse $response, ?int $latencyMs, ?int $userId = null): void
    {
        $this->db->insert(
            'INSERT INTO ai_calls (purpose, provider, model, prompt_version, input_sha256, status, error_kind, finish_reason, estimated_tokens, prompt_tokens, output_tokens, total_tokens, latency_ms, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                substr($request->purpose, 0, 40), $this->provider->name(), substr($response?->model ?? $this->provider->model(), 0, 100), substr($request->promptVersion, 0, 40),
                $request->inputHash(), $status, $kind, $response?->finishReason, $estimate, $response?->promptTokens, $response?->outputTokens, $response?->totalTokens,
                $latencyMs, $userId, $this->clock->nowDb(),
            ]
        );
    }
}

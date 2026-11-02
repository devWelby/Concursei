<?php

declare(strict_types=1);

namespace Concursei\Tests\Integration\Ai;

use Concursei\Core\Config;
use Concursei\Core\Container;
use Concursei\Core\Database\Database;
use Concursei\Core\Logging\Logger;
use Concursei\Modules\Ai\AiBudget;
use Concursei\Modules\Ai\AiError;
use Concursei\Modules\Ai\AiGateway;
use Concursei\Modules\Ai\AiProvider;
use Concursei\Modules\Ai\AiRequest;
use Concursei\Modules\Ai\DisabledProvider;
use Concursei\Modules\Ai\FakeProvider;
use Concursei\Tests\Support\IntegrationTestCase;
use DateTimeImmutable;

final class AiGatewayTest extends IntegrationTestCase
{
    private FakeProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:00:00'));
        $fake = $this->app->container->get(AiProvider::class);
        self::assertInstanceOf(FakeProvider::class, $fake, 'Nos testes, GEMINI_TRANSPORT=fake');
        $this->fake = $fake;
    }

    private function gateway(array $limits = []): AiGateway
    {
        $c = $this->app->container;
        $config = $c->get(Config::class);
        foreach ($limits as $key => $value) {
            $config = $config->with('ai.' . $key, $value);
        }
        $db = $c->get(Database::class);
        return new AiGateway($this->fake, new AiBudget($db, $this->clock, $config), $db, $this->clock, $config, $c->get(Logger::class));
    }

    private function aiRequest(string $text = 'Texto do edital com dados pessoais? Não: só texto público.'): AiRequest
    {
        return new AiRequest('edital_extraction', 'edital-v1', 'Extraia os campos.', $text, ['type' => 'OBJECT'], 1000);
    }

    public function testSuccessSettlesReservationAndLogsWithoutContent(): void
    {
        $this->fake->willReturn(['ok' => true], 1500);
        $response = $this->gateway()->call($this->aiRequest());
        self::assertSame(['ok' => true], $response->data);

        $budget = $this->db->all('SELECT period, reserved_tokens, used_tokens, calls FROM ai_budget ORDER BY period');
        self::assertSame([
            ['period' => 'd:2027-03-10', 'reserved_tokens' => 0, 'used_tokens' => 1500, 'calls' => 1],
            ['period' => 'm:2027-03', 'reserved_tokens' => 0, 'used_tokens' => 1500, 'calls' => 0],
        ], $budget);
        $call = $this->db->one('SELECT * FROM ai_calls');
        self::assertSame(['ok', 'edital-v1', 1500], [$call['status'], $call['prompt_version'], $call['total_tokens']]);
        $row = (string) json_encode($call);
        self::assertStringNotContainsString('edital com dados', $row, 'Registro não guarda o texto enviado');
        self::assertSame(64, strlen((string) $call['input_sha256']));
    }

    public function testCeilingsBlockBeforeCallingProvider(): void
    {
        $gw = $this->gateway(['daily_call_limit' => 2]);
        $this->fake->willReturn(['n' => 1]);
        $this->fake->willReturn(['n' => 2]);
        $gw->call($this->aiRequest());
        $gw->call($this->aiRequest());
        try {
            $gw->call($this->aiRequest());
            self::fail('Terceira chamada excede o limite diário');
        } catch (AiError $e) {
            self::assertSame('budget', $e->kind);
            self::assertTrue($e->retryable);
            self::assertSame(15 * 3600, $e->retryAfterSeconds, 'Renova à meia-noite de Brasília (15 h depois das 09:00)');
        }
        self::assertCount(2, $this->fake->requests, 'Provedor não é chamado acima do teto');

        $estimate = $this->aiRequest()->estimatedTokens();
        $tight = $this->gateway(['daily_token_limit' => 2000 + $estimate - 1, 'daily_call_limit' => 100]);
        try {
            $tight->call($this->aiRequest());
            self::fail('Reserva que ultrapassa o teto de tokens deve ser recusada');
        } catch (AiError $e) {
            self::assertSame('budget', $e->kind);
        }
        self::assertSame(0, (int) $this->db->scalar("SELECT reserved_tokens FROM ai_budget WHERE period = 'm:2027-03'"), 'Recusa no dia desfaz a reserva do mês');
    }

    public function testTimeoutChargesEstimateAndCircuitOpensAfterRepeatedFailures(): void
    {
        $gw = $this->gateway(['circuit_failures' => 3, 'circuit_cooldown_seconds' => 600]);
        $estimate = $this->aiRequest()->estimatedTokens();
        $this->fake->willFail(new AiError('timeout', true, null, true));
        try {
            $gw->call($this->aiRequest());
        } catch (AiError) {
        }
        self::assertSame($estimate, (int) $this->db->scalar("SELECT used_tokens FROM ai_budget WHERE period = 'd:2027-03-10'"), 'Resultado incerto conta a estimativa');

        $this->fake->willFail(new AiError('invalid_json', false));
        try {
            $gw->call($this->aiRequest());
        } catch (AiError) {
        }
        self::assertSame(0, (int) $this->db->scalar('SELECT consecutive_failures FROM ai_circuit'), 'Erro de conteúdo não conta para o circuito (e zera)');

        foreach (range(1, 3) as $_) {
            $this->fake->willFail(new AiError('unavailable', true));
            try {
                $gw->call($this->aiRequest());
            } catch (AiError) {
            }
        }
        $calls = count($this->fake->requests);
        try {
            $gw->call($this->aiRequest());
            self::fail('Circuito aberto');
        } catch (AiError $e) {
            self::assertSame(['circuit_open', true, 600], [$e->kind, $e->retryable, $e->retryAfterSeconds]);
        }
        self::assertCount($calls, $this->fake->requests, 'Circuito aberto não chama o provedor');

        $this->clock->setNow(new DateTimeImmutable('2027-03-10 12:10:01'));
        $this->fake->willReturn(['ok' => true]);
        $gw->call($this->aiRequest());
        self::assertNull($this->db->scalar('SELECT open_until FROM ai_circuit'), 'Sucesso após a pausa fecha o circuito');
    }

    public function testFakeTransportOutsideTestsIsDisabled(): void
    {
        $c = new Container();
        $config = new Config(['app' => ['env' => 'production'], 'ai' => ['transport' => 'fake']]);
        (require dirname(__DIR__, 3) . '/app/Modules/Ai/module.php')($this->app, $c, $config);
        self::assertInstanceOf(DisabledProvider::class, $c->get(AiProvider::class));

        $c = new Container();
        (require dirname(__DIR__, 3) . '/app/Modules/Ai/module.php')($this->app, $c, new Config(['app' => ['env' => 'local'], 'ai' => ['transport' => 'rest', 'api_key' => '', 'model' => '']]));
        self::assertFalse($c->get(AiProvider::class)->isConfigured(), 'Sem chave/modelo, a IA fica indisponível');
    }
}

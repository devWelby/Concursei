<?php
/**
 * Painel de saúde (somente administradores).
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array{counts:array<string,int>,stuck:int,oldest_pending:?string} $stats
 * @var list<array<string,mixed>> $heartbeats
 * @var array<string,array<string,mixed>> $tasks
 * @var list<array<string,mixed>> $failed
 * @var list<array<string,mixed>> $waiting
 * @var \DateTimeImmutable $now
 * @var array<string,mixed> $ai
 */
use Concursei\Modules\Catalog\Format;

$view->share('noindex', true);
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
$when = static fn (?string $utc): string => $utc !== null ? Format::localDate($utc, $tz, true) : '—';
$statusLabels = ['pending' => 'Na fila', 'running' => 'Em execução', 'retry_scheduled' => 'Nova tentativa agendada', 'completed' => 'Concluídos', 'failed' => 'Falharam', 'cancelled' => 'Cancelados'];
$byName = [];
foreach ($heartbeats as $hb) {
    $byName[$hb['name']] = $hb;
}
$selfBeat = $byName['schedule:run'] ?? null;
$cronLate = $selfBeat === null || $selfBeat['last_finished_at'] === null
    || $now->getTimestamp() - (new DateTimeImmutable((string) $selfBeat['last_finished_at'], new DateTimeZone('UTC')))->getTimestamp() > 1800;
?>
<?= $view->partial('admin/_nav', ['active' => 'sistema']) ?>
<h1>Saúde do sistema</h1>

<?php if ($cronLate): ?>
  <div class="alert alert-warning" role="status">
    O agendador (<code>php bin/console schedule:run</code>) não concluiu nenhuma execução nos últimos 30 minutos.
    Sem ele, a fila, os e-mails pendentes e as rotinas diárias não rodam. Confira o Cron da hospedagem.
  </div>
<?php endif; ?>

<ul class="stats" aria-label="Jobs por situação">
  <?php foreach (['pending', 'running', 'retry_scheduled', 'failed'] as $key): ?>
    <li class="card stat"><span class="stat-number"><?= (int) $stats['counts'][$key] ?></span> <?= e($statusLabels[$key]) ?></li>
  <?php endforeach; ?>
</ul>
<p class="meta">
  Jobs parados (lease vencido): <?= (int) $stats['stuck'] ?> ·
  Mais antigo aguardando: <?= e($when($stats['oldest_pending'])) ?> ·
  Concluídos retidos: <?= (int) $stats['counts']['completed'] ?> · Cancelados: <?= (int) $stats['counts']['cancelled'] ?>
</p>

<section aria-labelledby="agenda">
  <h2 id="agenda">Agenda do Cron</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Tarefa</th><th scope="col">Frequência</th><th scope="col">Último início</th><th scope="col">Último fim</th><th scope="col">Resultado</th></tr></thead>
      <tbody>
        <?php foreach (['schedule:run' => ['every_minutes' => null]] + $tasks as $name => $def): ?>
          <?php $hb = $byName[$name] ?? null; ?>
          <tr>
            <th scope="row"><code><?= e($name) ?></code></th>
            <td><?= isset($def['daily_at']) ? 'diária às ' . e($def['daily_at']) : (isset($def['every_minutes']) ? 'a cada ' . (int) $def['every_minutes'] . ' min' : 'cada chamada do Cron') ?></td>
            <td><?= e($when($hb['last_started_at'] ?? null)) ?></td>
            <td><?= e($when($hb['last_finished_at'] ?? null)) ?><?= $hb !== null && $hb['last_duration_ms'] !== null ? ' <span class="meta">(' . (int) $hb['last_duration_ms'] . ' ms)</span>' : '' ?></td>
            <td>
              <?php if ($hb === null || $hb['last_status'] === null): ?><span class="muted">nunca rodou</span>
              <?php elseif ($hb['last_status'] === 'ok'): ?><span class="badge badge-success">ok</span>
              <?php elseif ($hb['last_status'] === 'running'): ?><span class="badge">rodando</span>
              <?php else: ?><span class="badge badge-error">falhou</span> <span class="meta"><?= e($hb['last_message'] ?? '') ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section aria-labelledby="ia">
  <h2 id="ia">IA (Gemini)</h2>
  <?php
  $budget = $ai['budget'];
  $circuitOpen = $ai['circuit'] !== null && $ai['circuit']['open_until'] !== null && strtotime($ai['circuit']['open_until'] . ' UTC') > $now->getTimestamp();
  $num = static fn (int $n): string => number_format($n, 0, ',', '.');
  ?>
  <?php if (!$ai['configured']): ?>
    <div class="alert alert-info" role="status">IA indisponível: defina <code>GEMINI_API_KEY</code> e <code>GEMINI_MODEL</code> no .env privado do servidor. Sem isso, a extração assistida de editais fica desativada (nada é simulado).</div>
  <?php elseif ($circuitOpen): ?>
    <div class="alert alert-warning" role="status">Chamadas pausadas até <?= e($when($ai['circuit']['open_until'])) ?> após falhas seguidas (último erro: <?= e((string) $ai['circuit']['last_error_kind']) ?>). A fila tenta de novo depois.</div>
  <?php endif; ?>
  <dl class="facts card">
    <dt>Modelo</dt><dd><?= $ai['model'] !== '' ? '<code>' . e($ai['model']) . '</code>' : 'não definido' ?></dd>
    <dt>Hoje</dt><dd><?= e($num($budget['day']['used_tokens'])) ?> tokens usados + <?= e($num($budget['day']['reserved_tokens'])) ?> reservados de <?= e($num($budget['limits']['day_tokens'])) ?> · <?= (int) $budget['day']['calls'] ?>/<?= (int) $budget['limits']['day_calls'] ?> chamadas</dd>
    <dt>Mês</dt><dd><?= e($num($budget['month']['used_tokens'])) ?> tokens usados de <?= e($num($budget['limits']['month_tokens'])) ?></dd>
  </dl>
  <?php if ($ai['recent'] !== []): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Quando</th><th scope="col">Finalidade</th><th scope="col">Resultado</th><th scope="col">Tokens (estimado → real)</th><th scope="col">Tempo</th></tr></thead>
        <tbody>
          <?php foreach ($ai['recent'] as $call): ?>
            <tr>
              <td><?= e($when($call['created_at'])) ?></td>
              <td><code><?= e($call['purpose']) ?></code></td>
              <td><?= $call['status'] === 'ok' ? '<span class="badge badge-success">ok</span>' : '<span class="badge badge-error">' . e((string) $call['error_kind']) . '</span>' ?></td>
              <td><?= e($num((int) $call['estimated_tokens'])) ?> → <?= $call['total_tokens'] !== null ? e($num((int) $call['total_tokens'])) : '—' ?></td>
              <td><?= $call['latency_ms'] !== null ? (int) $call['latency_ms'] . ' ms' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section aria-labelledby="falhas">
  <h2 id="falhas">Jobs que falharam</h2>
  <?php if ($failed === []): ?>
    <p>Nenhuma falha definitiva.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Job</th><th scope="col">Tentativas</th><th scope="col">Erro</th><th scope="col">Quando</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
        <tbody>
          <?php foreach ($failed as $j): ?>
            <tr>
              <td>#<?= (int) $j['id'] ?> <code><?= e($j['type']) ?></code></td>
              <td><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td>
              <td><?= e($j['last_error'] ?? '') ?></td>
              <td><?= e($when($j['finished_at'])) ?></td>
              <td>
                <form method="post" action="/admin/sistema/jobs/<?= (int) $j['id'] ?>/reprocessar" class="inline-form">
                  <?= $h->csrfField() ?>
                  <button class="button button-secondary button-small" type="submit">Reprocessar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section aria-labelledby="aguardando">
  <h2 id="aguardando">Aguardando execução</h2>
  <?php if ($waiting === []): ?>
    <p>Fila vazia.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Job</th><th scope="col">Situação</th><th scope="col">Disponível a partir de</th><th scope="col">Último erro</th><th scope="col"><span class="visually-hidden">Ações</span></th></tr></thead>
        <tbody>
          <?php foreach ($waiting as $j): ?>
            <tr>
              <td>#<?= (int) $j['id'] ?> <code><?= e($j['type']) ?></code></td>
              <td><?= e($statusLabels[$j['status']] ?? $j['status']) ?> (<?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?>)</td>
              <td><?= e($when($j['available_at'])) ?></td>
              <td><?= e($j['last_error'] ?? '') ?></td>
              <td>
                <form method="post" action="/admin/sistema/jobs/<?= (int) $j['id'] ?>/cancelar" class="inline-form">
                  <?= $h->csrfField() ?>
                  <button class="button button-secondary button-small" type="submit">Cancelar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

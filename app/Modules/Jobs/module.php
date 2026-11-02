<?php

declare(strict_types=1);

use Concursei\Core\App;
use Concursei\Core\Config;
use Concursei\Core\Console\Console;
use Concursei\Core\Container;
use Concursei\Modules\Jobs\AdminSystemController;
use Concursei\Modules\Jobs\JobRegistry;
use Concursei\Modules\Jobs\Queue;
use Concursei\Modules\Jobs\Scheduler;
use Concursei\Modules\Jobs\Worker;

return static function (App $app, Container $c, Config $config): void {
    // Registro compartilhado: os módulos seguintes registram seus tipos de job aqui.
    $c->singleton(JobRegistry::class, static fn (Container $c): JobRegistry => new JobRegistry($c));

    $r = $app->router();
    $admin = ['middleware' => ['auth', 'can:jobs.manage', 'throttle:admin']];
    $r->get('/admin/sistema', [AdminSystemController::class, 'index'], $admin);
    $r->post('/admin/sistema/jobs/{id:\d+}/reprocessar', [AdminSystemController::class, 'requeue'], $admin);
    $r->post('/admin/sistema/jobs/{id:\d+}/cancelar', [AdminSystemController::class, 'cancel'], $admin);

    $app->registerConsole(static function (Console $console) use ($c, $config): void {
        $console->register('queue:work', 'Executa jobs da fila (Cron). --max-jobs=N --max-seconds=S', static function (array $o, array $a, Console $io) use ($c, $config): int {
            $lease = $config->int('jobs.lease_seconds', 300);
            $maxJobs = isset($o['max-jobs']) ? max(1, min(100, (int) $o['max-jobs'])) : 5;
            // O tempo de execução nunca alcança o lease: job em andamento não pode ser reservado por outro worker.
            $maxSeconds = isset($o['max-seconds']) ? max(1, min($lease - 30, (int) $o['max-seconds'])) : 40;
            $stats = $c->get(Worker::class)->run($maxJobs, $maxSeconds);
            $io->line(sprintf('Concluídos: %d · reagendados: %d · falhas definitivas: %d', $stats['completed'], $stats['retry'], $stats['failed']));
            return 0;
        });

        $console->register('jobs:purge', 'Remove jobs antigos conforme a retenção (Cron).', static function (array $o, array $a, Console $io) use ($c): int {
            $io->line('Jobs removidos: ' . $c->get(Queue::class)->purge());
            return 0;
        });

        $console->register('jobs:retry', 'Devolve à fila um job falho ou cancelado. Uso: jobs:retry <id>', static function (array $o, array $a, Console $io) use ($c): int {
            $id = (int) ($a[0] ?? 0);
            if ($id < 1 || !$c->get(Queue::class)->requeue($id)) {
                $io->error('Job inexistente ou não está falho/cancelado.');
                return 1;
            }
            $io->line("Job #{$id} devolvido à fila.");
            return 0;
        });

        $console->register('schedule:run', 'Roda as tarefas vencidas da agenda (um único Cron, ex.: a cada 5 min). --only=<tarefa> força uma.', static function (array $o, array $a, Console $io) use ($c, $console): int {
            $only = isset($o['only']) && is_string($o['only']) ? $o['only'] : null;
            $scheduler = $c->get(Scheduler::class);
            if ($only !== null && !isset($scheduler->tasks()[$only])) {
                $io->error("Tarefa fora da agenda: {$only}");
                return 1;
            }
            $report = $scheduler->runDue(static function (string $command, array $options) use ($console): int {
                $argv = ['console', $command];
                foreach ($options as $k => $v) {
                    $argv[] = '--' . $k . '=' . $v;
                }
                return $console->run($argv);
            }, $only);
            foreach ($report as $task => $status) {
                if ($status !== 'skipped') {
                    $io->line(sprintf('%-22s %s', $task, $status));
                }
            }
            return in_array('failed', $report, true) ? 1 : 0;
        });
    });
};

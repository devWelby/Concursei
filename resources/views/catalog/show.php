<?php
/**
 * Detalhe público: tudo vem do snapshot da revisão publicada + modelo de leitura.
 * @var \Concursei\Core\View\TemplateHelper $h
 * @var \Concursei\Core\View\View $view
 * @var array<string,mixed> $contest
 * @var \Concursei\Shared\Auth\AuthUser|null $user
 * @var int|null $favoriteId
 * @var \DateTimeImmutable $today
 */
use Concursei\Modules\Catalog\Format;
use Concursei\Modules\Catalog\Vocabulary;

$s = $contest['snapshot'];
$c = $s['contest'];
$org = $s['organization'];
$board = $s['exam_board'];
$tz = (string) $h->config('app.display_timezone', 'America/Sao_Paulo');
$todayStr = $today->format('Y-m-d');
$view->share('canonical', $h->absoluteUrl('/concursos/' . $contest['slug']));
$levels = array_values(array_unique(array_map(static fn (array $o): string => Vocabulary::label(Vocabulary::EDUCATION_LEVELS, $o['education_level']), $s['offers'])));
$regStart = null;
$regEnd = null;
foreach ($s['events'] as $ev) {
    if ($ev['type'] === 'registration_start' && $regStart === null) {
        $regStart = $ev;
    }
    if ($ev['type'] === 'registration_end') {
        $regEnd = $ev;
    }
}
$nextCountdown = $contest['next_event_on'] !== null ? Format::countdown((string) $contest['next_event_on'], $today) : null;
$paragraphs = $c['summary'] !== null ? preg_split('/\R{2,}/u', trim((string) $c['summary'])) : [];
?>
<article aria-labelledby="titulo">
  <p class="meta"><a href="/concursos">Concursos</a> › <?= e($org['acronym'] ?? $org['name']) ?></p>
  <h1 id="titulo"><?= e($c['title']) ?></h1>
  <p>
    <span class="<?= e(Format::phaseClass((string) $contest['phase'])) ?>"><?= e(Vocabulary::label(Vocabulary::PHASES, $contest['phase'])) ?></span>
    <span class="meta">
      <?= e($org['name']) ?> · <?= $board !== null ? 'Banca ' . e($board['name']) : 'Banca a definir' ?> · <?= e($c['uf'] !== null ? Vocabulary::label(Vocabulary::UFS, $c['uf']) : 'Nacional / várias UFs') ?>
    </span>
  </p>

  <section id="acompanhar" class="card" aria-labelledby="acompanhar-titulo">
    <h2 id="acompanhar-titulo" class="visually-hidden">Acompanhar</h2>
    <?php if ($user === null): ?>
      <p><a class="button" href="/concursos/<?= e($contest['slug']) ?>/acompanhar">Acompanhar este concurso</a> <span class="muted">Entre ou crie sua conta para receber as datas no seu painel.</span></p>
    <?php elseif ($favoriteId === null): ?>
      <form method="post" action="/favoritos" class="inline-form">
        <?= $h->csrfField() ?>
        <input type="hidden" name="contest_id" value="<?= (int) $contest['contest_id'] ?>">
        <button class="button" type="submit">Acompanhar este concurso</button>
      </form>
    <?php else: ?>
      <p>Você acompanha este concurso. <a href="/painel">Ver meu painel</a></p>
      <form method="post" action="/favoritos/<?= (int) $favoriteId ?>" class="inline-form">
        <?= $h->csrfField() ?><?= $h->methodField('DELETE') ?>
        <button class="button button-secondary button-small" type="submit">Deixar de acompanhar</button>
      </form>
    <?php endif; ?>
  </section>

  <section aria-labelledby="resumo">
    <h2 id="resumo">Resumo</h2>
    <dl class="facts">
      <dt>Situação</dt><dd><?= e(Vocabulary::label(Vocabulary::PHASES, $contest['phase'])) ?></dd>
      <?php if ($contest['next_event_on'] !== null): ?>
        <dt>Próxima data</dt><dd><?= e(Vocabulary::label(Vocabulary::EVENT_TYPES, $contest['next_event_type'])) ?>: <?= e(Format::eventDate((string) $contest['next_event_on'], 'day')) ?><?= $nextCountdown !== null ? ' (' . e($nextCountdown) . ')' : '' ?></dd>
      <?php endif; ?>
      <dt>Inscrições</dt>
      <dd>
        <?php if ($regStart === null && $regEnd === null): ?>A definir<?php else: ?>
          <?= e($regStart !== null ? Format::eventDate($regStart['date'], $regStart['precision']) : 'A definir') ?> a <?= e($regEnd !== null ? Format::eventDate($regEnd['date'], $regEnd['precision'], $regEnd['time']) : 'a definir') ?>
        <?php endif; ?>
      </dd>
      <dt>Vagas</dt><dd><?= e(Format::vacancies($contest['vacancies_total'] !== null ? (int) $contest['vacancies_total'] : null, (int) $contest['has_reserve'] === 1)) ?></dd>
      <dt>Remuneração</dt><dd><?= $contest['salary_max_cents'] !== null ? 'até ' . e(Format::money((int) $contest['salary_max_cents'])) : 'Não informada' ?></dd>
      <dt>Escolaridade</dt><dd><?= $levels !== [] ? e(implode(', ', $levels)) : 'Não informada' ?></dd>
      <dt>Taxa</dt><dd><?= e(Format::money($c['fee_cents'] !== null ? (int) $c['fee_cents'] : null)) ?><?= $c['fee_note'] !== null ? ' — ' . e($c['fee_note']) : '' ?></dd>
    </dl>
    <?php foreach ($paragraphs as $p): ?><p><?= nl2br(e($p)) ?></p><?php endforeach; ?>
  </section>

  <section aria-labelledby="datas">
    <h2 id="datas">Datas</h2>
    <?php if ($s['events'] === []): ?>
      <p>Nenhuma data divulgada ainda.</p>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($s['events'] as $ev): ?>
          <?php $past = $ev['precision'] === 'day' && $ev['date'] !== null && $ev['date'] < $todayStr; ?>
          <li<?= $past ? ' class="is-past"' : '' ?>>
            <span class="when"><?= e(Format::eventDate($ev['date'], $ev['precision'], $ev['time'])) ?></span>
            <span><?= e($ev['label'] ?? Vocabulary::label(Vocabulary::EVENT_TYPES, $ev['type'])) ?><?= $past ? ' <span class="visually-hidden">(já passou)</span>' : '' ?><?php if ($ev['note'] !== null): ?><br><span class="meta"><?= e($ev['note']) ?></span><?php endif; ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="source-note">Datas marcadas "(previsto)" ainda não têm dia confirmado em documento oficial.</p>
    <?php endif; ?>
  </section>

  <section aria-labelledby="cargos">
    <h2 id="cargos">Cargos</h2>
    <?php if ($s['offers'] === []): ?>
      <p>Cargos ainda não divulgados.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th scope="col">Cargo</th><th scope="col">Escolaridade</th><th scope="col">Vagas</th><th scope="col">Remuneração</th></tr></thead>
          <tbody>
            <?php foreach ($s['offers'] as $o): ?>
              <tr>
                <td>
                  <?= e($o['title']) ?>
                  <?php if ($o['location'] !== null || $o['workload_hours'] !== null): ?><br><span class="meta"><?= e(implode(' · ', array_filter([$o['location'], $o['workload_hours'] !== null ? $o['workload_hours'] . ' h semanais' : null]))) ?></span><?php endif; ?>
                  <?php if ($o['requirements'] !== null): ?><details><summary>Requisitos</summary><p><?= nl2br(e($o['requirements'])) ?></p></details><?php endif; ?>
                </td>
                <td><?= e(Vocabulary::label(Vocabulary::EDUCATION_LEVELS, $o['education_level'])) ?></td>
                <td><?= e(Format::vacancies($o['vacancies'], (bool) $o['reserve'])) ?></td>
                <td><?= e(Format::money($o['salary_cents'])) ?><?php if ($o['salary_note'] !== null): ?><br><span class="meta"><?= e($o['salary_note']) ?></span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section aria-labelledby="fonte">
    <h2 id="fonte">Fonte e links oficiais</h2>
    <ul>
      <?php if ($c['official_url'] !== null): ?><li><a href="<?= e_url($c['official_url']) ?>" rel="nofollow noopener noreferrer">Página oficial do concurso</a></li><?php endif; ?>
      <?php if ($c['registration_url'] !== null): ?><li><a href="<?= e_url($c['registration_url']) ?>" rel="nofollow noopener noreferrer">Página de inscrição</a></li><?php endif; ?>
      <?php if ($c['source_url'] !== null): ?><li><a href="<?= e_url($c['source_url']) ?>" rel="nofollow noopener noreferrer">Documento oficial usado na conferência</a><?= $c['source_published_on'] !== null ? ' (publicado em ' . e(Format::date($c['source_published_on'])) . ')' : '' ?></li><?php endif; ?>
    </ul>
    <p class="source-note">
      Informações conferidas pela equipe com o documento oficial em <?= e(Format::localDate($contest['published_at'], $tz)) ?>.
      O edital e seus comunicados oficiais prevalecem sobre este resumo. Encontrou algo errado? <a href="/correcoes">Informe uma correção</a>.
    </p>
  </section>

  <section aria-labelledby="historico">
    <h2 id="historico">Histórico de atualizações</h2>
    <ol class="timeline" reversed>
      <?php foreach ($contest['history'] as $rev): ?>
        <li>
          <span class="when"><?= e(Format::localDate($rev['published_at'], $tz)) ?></span>
          <span><?php if ((int) $rev['is_correction'] === 1): ?><span class="badge badge-warn">Correção</span> <?php endif; ?><?= e($rev['change_note']) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
</article>

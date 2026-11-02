<?php

declare(strict_types=1);

/**
 * Versões vigentes dos documentos legais. Alterar o texto => incrementar a versão
 * (consentimentos ficam registrados com a versão aceita).
 * @var \Concursei\Core\Env $env
 */
return [
    'terms_version' => '2026-11-02',
    'privacy_version' => '2026-11-02',
    // Público adulto (18+): ver D-014 em docs/DECISIONS.md.
    'minimum_age' => 18,
];

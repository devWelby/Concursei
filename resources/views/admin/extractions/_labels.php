<?php
/** Rótulos compartilhados das telas de extração assistida. */
return [
    'status' => [
        'queued' => ['Na fila', 'badge'], 'running' => ['Processando', 'badge'], 'ready' => ['Aguardando revisão', 'badge badge-warn'],
        'failed' => ['Falhou', 'badge badge-error'], 'applied' => ['Aplicada ao rascunho', 'badge badge-success'], 'rejected' => ['Descartada', 'badge'],
    ],
    'diff' => [
        'new' => ['Novo no rascunho', 'badge badge-success'], 'changed' => ['Altera o rascunho', 'badge badge-warn'],
        'same' => ['Igual ao rascunho', 'badge'], 'blocked' => ['Bloqueado', 'badge badge-error'],
    ],
    'document_kind' => [
        'edital_abertura' => 'Edital de abertura', 'retificacao' => 'Retificação', 'cronograma' => 'Cronograma',
        'resultado' => 'Resultado', 'outro' => 'Outro documento',
    ],
];

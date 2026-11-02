<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

use Concursei\Core\Validation\Validator;
use Concursei\Core\View\View;

/**
 * Base de controllers: apenas orquestração HTTP. Regras de negócio ficam em
 * Services; acesso a dados em Repositories; autorização em Policies.
 */
abstract class Controller
{
    public function __construct(protected View $view)
    {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200, ?string $layout = 'layouts/app'): Response
    {
        return Response::html($this->view->render($template, $data, $layout), $status);
    }

    /**
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed>
     */
    protected function validate(Request $request, array $rules, array $labels = [], ?string $back = null): array
    {
        $input = [];
        foreach (array_keys($rules) as $field) {
            $input[$field] = $request->input($field);
            if (str_contains($rules[$field], 'confirmed')) {
                $input[$field . '_confirmation'] = $request->input($field . '_confirmation');
            }
        }
        $result = Validator::validate($input, $rules, $labels);
        if ($result['errors'] !== []) {
            throw new ValidationFailed($result['errors'], $back, $input);
        }
        return $result['data'];
    }
}

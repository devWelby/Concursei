<?php

declare(strict_types=1);

namespace Concursei\Core\Validation;

/**
 * Validação no servidor com regras explícitas por campo. Só os campos com
 * regra entram em $data (proteção contra mass assignment). Mensagens em pt-BR.
 *
 * Regras: required, nullable, string, int, bool, email, min:n, max:n, in:a,b,
 * date (Y-m-d), datetime (Y-m-d H:i ou Y-m-d\TH:i), regex:/.../, slug, url (https),
 * confirmed, ints (lista de inteiros positivos), password.
 */
final class Validator
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $rules
     * @return array{data:array<string,mixed>,errors:array<string,list<string>>}
     */
    public static function validate(array $input, array $rules, array $labels = []): array
    {
        $data = [];
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? $field;
            $list = self::parseRules($ruleString);
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                // Remove caracteres de controle (exceto quebras de linha/tab).
                $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
            }
            $isEmpty = $value === null || $value === '' || $value === [];
            if ($isEmpty) {
                if (array_key_exists('required', $list)) {
                    $errors[$field][] = "Informe {$label}.";
                } elseif (array_key_exists('bool', $list)) {
                    $data[$field] = false;
                } else {
                    $data[$field] = null;
                }
                continue;
            }
            $fieldErrors = [];
            foreach ($list as $rule => $arg) {
                $err = self::apply($rule, $arg, $value, $label, $input, $field);
                if ($err !== null) {
                    $fieldErrors[] = $err;
                    break;
                }
            }
            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
                continue;
            }
            $data[$field] = $value;
        }
        return ['data' => $data, 'errors' => $errors];
    }

    /** @return array<string,string|null> */
    private static function parseRules(string $rules): array
    {
        $out = [];
        // regex pode conter "|", então é tratado separadamente.
        if (preg_match('/regex:(\/.*\/[a-z]*)/', $rules, $m)) {
            $out['regex'] = $m[1];
            $rules = str_replace('regex:' . $m[1], '', $rules);
        }
        foreach (explode('|', $rules) as $r) {
            if ($r === '') {
                continue;
            }
            [$name, $arg] = array_pad(explode(':', $r, 2), 2, null);
            $out[$name] = $arg;
        }
        // Tipos primeiro, para min/max operarem sobre o valor convertido.
        $order = ['required' => 0, 'nullable' => 0, 'string' => 1, 'int' => 1, 'bool' => 1, 'ints' => 1];
        uksort($out, static fn ($a, $b) => ($order[$a] ?? 2) <=> ($order[$b] ?? 2));
        return $out;
    }

    private static function apply(string $rule, ?string $arg, mixed &$value, string $label, array $input, string $field): ?string
    {
        switch ($rule) {
            case 'required':
            case 'nullable':
                return null;
            case 'string':
                return is_string($value) ? null : "{$label} inválido.";
            case 'int':
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,18}$/', $value))) {
                    $value = (int) $value;
                    return null;
                }
                return "{$label} deve ser um número inteiro.";
            case 'bool':
                $value = in_array($value, [true, 1, '1', 'on', 'true', 'sim'], true);
                return null;
            case 'ints':
                if (!is_array($value)) {
                    $value = [$value];
                }
                $clean = [];
                foreach ($value as $v) {
                    if (!is_scalar($v) || !preg_match('/^[1-9]\d{0,18}$/', (string) $v)) {
                        return "{$label} contém valor inválido.";
                    }
                    $clean[] = (int) $v;
                }
                $value = array_values(array_unique($clean));
                return null;
            case 'email':
                if (!is_string($value) || strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return 'Informe um e-mail válido.';
                }
                $value = mb_strtolower($value);
                return null;
            case 'min':
                $n = (int) $arg;
                if (is_int($value)) {
                    return $value < $n ? "{$label} deve ser no mínimo {$n}." : null;
                }
                if (is_array($value)) {
                    return count($value) < $n ? "Selecione ao menos {$n} item(ns) em {$label}." : null;
                }
                return mb_strlen((string) $value) < $n ? "{$label} deve ter ao menos {$n} caracteres." : null;
            case 'max':
                $n = (int) $arg;
                if (is_int($value)) {
                    return $value > $n ? "{$label} deve ser no máximo {$n}." : null;
                }
                if (is_array($value)) {
                    return count($value) > $n ? "Selecione no máximo {$n} item(ns) em {$label}." : null;
                }
                return mb_strlen((string) $value) > $n ? "{$label} deve ter no máximo {$n} caracteres." : null;
            case 'in':
                $allowed = explode(',', (string) $arg);
                return in_array((string) $value, $allowed, true) ? null : "{$label}: opção inválida.";
            case 'date':
                $d = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                return ($d && $d->format('Y-m-d') === $value) ? null : "{$label}: data inválida (use AAAA-MM-DD).";
            case 'datetime':
                if (!is_string($value)) {
                    return "{$label}: data/hora inválida.";
                }
                $v = str_replace('T', ' ', $value);
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', substr($v, 0, 16));
                if (!$d || $d->format('Y-m-d H:i') !== substr($v, 0, 16)) {
                    return "{$label}: data/hora inválida.";
                }
                $value = $d->format('Y-m-d H:i:00');
                return null;
            case 'regex':
                return (is_string($value) && preg_match((string) $arg, $value)) ? null : "{$label} em formato inválido.";
            case 'slug':
                return (is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) && strlen($value) <= 190) ? null : "{$label}: use letras minúsculas, números e hífens.";
            case 'url':
                if (!is_string($value) || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false
                    || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https' || parse_url($value, PHP_URL_USER) !== null) {
                    return "{$label}: informe uma URL https válida.";
                }
                return null;
            case 'confirmed':
                return (($input[$field . '_confirmation'] ?? null) === $value) ? null : "A confirmação de {$label} não confere.";
            case 'password':
                return PasswordPolicy::check((string) $value);
            default:
                throw new \InvalidArgumentException('Regra de validação desconhecida: ' . $rule);
        }
    }
}

<?php

declare(strict_types=1);

namespace Concursei\Core\Validation;

/**
 * Política de senha alinhada ao NIST SP 800-63B: comprimento mínimo, sem regras
 * de composição, bloqueio de senhas comuns, e teto alto (256) apenas contra DoS.
 * Senhas longas NUNCA são truncadas silenciosamente (ver PasswordHasher).
 */
final class PasswordPolicy
{
    public const MIN = 10;
    public const MAX = 256;

    private const COMMON = [
        '1234567890', '12345678910', '0123456789', 'qwertyuiop', 'password123', 'senha12345', 'senhasenha',
        'iloveyou12', 'abcdefghij', 'aaaaaaaaaa', '1111111111', '1q2w3e4r5t', 'qwerty1234', 'brasil2026',
        'concursei1', 'concurso123', 'concursopublico', 'administrador', 'flamengo123', 'corinthians',
        'palmeiras1', 'mudar12345', 'trocar1234', 'password1234', 'senha123456', '123456789a', 'a123456789',
    ];

    public static function check(string $password): ?string
    {
        $len = mb_strlen($password);
        if ($len < self::MIN) {
            return 'A senha deve ter ao menos ' . self::MIN . ' caracteres.';
        }
        if ($len > self::MAX) {
            return 'A senha deve ter no máximo ' . self::MAX . ' caracteres.';
        }
        if (in_array(mb_strtolower($password), self::COMMON, true) || count(array_unique(mb_str_split($password))) < 4) {
            return 'Essa senha é muito comum ou previsível. Escolha outra.';
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Core;

use Concursei\Core\Validation\PasswordPolicy;
use Concursei\Core\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidDataIsTypedAndUnknownFieldsDropped(): void
    {
        $r = Validator::validate(
            ['email' => '  Fulano@Example.COM ', 'age' => '30', 'agree' => 'on', 'role' => 'admin', 'kind' => 'b'],
            ['email' => 'required|email', 'age' => 'required|int|min:18|max:120', 'agree' => 'bool', 'kind' => 'in:a,b']
        );
        self::assertSame([], $r['errors']);
        self::assertSame('fulano@example.com', $r['data']['email']);
        self::assertSame(30, $r['data']['age']);
        self::assertTrue($r['data']['agree']);
        self::assertArrayNotHasKey('role', $r['data']);
    }

    public function testErrorsInPortuguese(): void
    {
        $r = Validator::validate(['email' => 'x', 'age' => '10', 'kind' => 'z', 'd' => '2026-02-30'], [
            'email' => 'required|email', 'age' => 'int|min:18', 'kind' => 'in:a,b', 'd' => 'date', 'name' => 'required|string',
        ], ['name' => 'o nome']);
        self::assertSame('Informe um e-mail válido.', $r['errors']['email'][0]);
        self::assertStringContainsString('no mínimo 18', $r['errors']['age'][0]);
        self::assertArrayHasKey('kind', $r['errors']);
        self::assertArrayHasKey('d', $r['errors'], '30/02 não é data válida');
        self::assertSame('Informe o nome.', $r['errors']['name'][0]);
    }

    public function testArraysAreRejectedForStringFields(): void
    {
        $r = Validator::validate(['name' => ['x']], ['name' => 'required|string|max:10']);
        self::assertArrayHasKey('name', $r['errors']);
    }

    public function testUrlRuleRequiresHttpsWithoutCredentials(): void
    {
        $rules = ['u' => 'url'];
        self::assertSame([], Validator::validate(['u' => 'https://www.gov.br/edital.pdf'], $rules)['errors']);
        self::assertNotSame([], Validator::validate(['u' => 'http://www.gov.br'], $rules)['errors']);
        self::assertNotSame([], Validator::validate(['u' => 'https://user:pw@host.example'], $rules)['errors']);
        self::assertNotSame([], Validator::validate(['u' => 'javascript:alert(1)'], $rules)['errors']);
    }

    public function testControlCharsRemoved(): void
    {
        $r = Validator::validate(['n' => "abc\x00\x07def"], ['n' => 'string']);
        self::assertSame('abcdef', $r['data']['n']);
    }

    public function testPasswordPolicy(): void
    {
        self::assertNotNull(PasswordPolicy::check('curta'));
        self::assertNotNull(PasswordPolicy::check('1234567890'));
        self::assertNotNull(PasswordPolicy::check('aaaaaaaaaaaa'));
        self::assertNull(PasswordPolicy::check('cavalo correto bateria grampo'));
        self::assertNull(PasswordPolicy::check(str_repeat('ab1-', 50)), 'Senhas longas (200 chars) são aceitas');
        self::assertNotNull(PasswordPolicy::check(str_repeat('x', 257)));
    }

    public function testConfirmedRule(): void
    {
        $r = Validator::validate(['p' => 'a', 'p_confirmation' => 'b'], ['p' => 'required|confirmed'], ['p' => 'a senha']);
        self::assertSame('A confirmação de a senha não confere.', $r['errors']['p'][0]);
    }
}

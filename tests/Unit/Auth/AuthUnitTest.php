<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Auth;

use Concursei\Modules\Auth\PasswordHasher;
use Concursei\Modules\Auth\UserRepository;
use Concursei\Modules\Mail\EmailRenderer;
use Concursei\Shared\Auth\Permissions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthUnitTest extends TestCase
{
    public function testArgonHashVerifiesAndRejects(): void
    {
        $h = new PasswordHasher();
        $hash = $h->hash('frase de teste longa');
        self::assertTrue($h->verify('frase de teste longa', $hash));
        self::assertFalse($h->verify('frase de teste longA', $hash));
        self::assertFalse($h->needsRehash($hash));
    }

    public function testBcryptFallbackDoesNotTruncateLongPasswords(): void
    {
        $h = new PasswordHasher(false);
        $base = str_repeat('a', 80);
        $hash = $h->hash($base . 'X');
        self::assertStringStartsWith('$2y$', $hash);
        self::assertTrue($h->verify($base . 'X', $hash));
        self::assertFalse($h->verify($base . 'Y', $hash), 'Diferença após o byte 72 precisa contar (bcrypt puro truncaria)');
    }

    public function testVerifyNeverThrowsOnGarbageHash(): void
    {
        self::assertFalse((new PasswordHasher())->verify('qualquer', 'nao-e-um-hash'));
    }

    public function testPermissionsByRole(): void
    {
        self::assertTrue(Permissions::allows('student', 'study.use'));
        self::assertFalse(Permissions::allows('student', 'admin.access'));
        self::assertTrue(Permissions::allows('editor', 'contests.publish'));
        self::assertFalse(Permissions::allows('editor', 'users.manage'), 'Editor não gerencia usuários');
        self::assertTrue(Permissions::allows('admin', 'users.manage'));
        self::assertSame([], Permissions::forRole('desconhecido'));
    }

    /** @return iterable<string,array{string,string}> */
    public static function names(): iterable
    {
        yield 'normal' => ['Maria da Silva', 'Maria da Silva'];
        yield 'quebras' => ["Ana\r\nBeatriz", 'Ana Beatriz'];
        yield 'url' => ['Clique https://golpe.example', ''];
        yield 'dominio' => ['ganhe em golpe.com', ''];
        yield 'email' => ['fale com x@golpe.example', ''];
        yield 'www' => ['acesse www.golpe', ''];
        yield 'sem-ponto' => ['www golpe', 'www golpe'];
        yield 'html' => ['<b>oi</b>', ''];
        yield 'longo' => [str_repeat('é', 60), str_repeat('é', 40)];
    }

    #[DataProvider('names')]
    public function testEmailSafeNameNeutralizesLinks(string $in, string $out): void
    {
        self::assertSame($out, EmailRenderer::safeName($in));
    }

    public function testTextToHtmlEscapesAndOnlyLinksHttps(): void
    {
        $html = EmailRenderer::textToHtml("<img src=x onerror=alert(1)>\nhttps://concursei.example/a?b=1&c=2\njavascript:alert(1)\nhttp://inseguro.example");
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<a href="https://concursei.example/a?b=1&amp;c=2">', $html);
        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringNotContainsString('href="http://', $html);
    }

    public function testDisplayNameNormalization(): void
    {
        self::assertSame('Ana Maria', UserRepository::normalizeName("  Ana\t\n  Maria  "));
    }
}

<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Core;

use Concursei\Core\Config;
use Concursei\Core\Env;
use PHPUnit\Framework\TestCase;

final class EnvConfigTest extends TestCase
{
    public function testParsesQuotedCommentedAndInvalidLines(): void
    {
        $values = Env::parse(<<<'ENV'
            # comentário
            APP_NAME="Concursei Teste"
            EMPTY=
            SINGLE='literal $x'
            INLINE=valor # comentário
            export EXPORTED=1
            lower_case=ignorada
            ESCAPED="linha1\nlinha2"
            ENV);
        self::assertSame('Concursei Teste', $values['APP_NAME']);
        self::assertSame('', $values['EMPTY']);
        self::assertSame('literal $x', $values['SINGLE']);
        self::assertSame('valor', $values['INLINE']);
        self::assertSame('1', $values['EXPORTED']);
        self::assertArrayNotHasKey('lower_case', $values);
        self::assertSame("linha1\nlinha2", $values['ESCAPED']);
    }

    public function testTypedAccessors(): void
    {
        $env = new Env(['FLAG' => 'true', 'NUM' => '42', 'BAD' => '4x', 'LIST' => 'a, b,,c']);
        self::assertTrue($env->bool('FLAG'));
        self::assertSame(42, $env->int('NUM'));
        self::assertSame(7, $env->int('BAD', 7));
        self::assertSame(['a', 'b', 'c'], $env->list('LIST'));
    }

    public function testConfigDotNotationAndWith(): void
    {
        $config = new Config(['db' => ['host' => 'h', 'port' => 3306]]);
        self::assertSame('h', $config->get('db.host'));
        self::assertNull($config->get('db.missing'));
        $copy = $config->with('db.host', 'outro');
        self::assertSame('outro', $copy->get('db.host'));
        self::assertSame('h', $config->get('db.host'), 'Config original permanece imutável');
    }
}

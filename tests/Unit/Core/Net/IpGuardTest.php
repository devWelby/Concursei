<?php

declare(strict_types=1);

namespace Concursei\Tests\Unit\Core\Net;

use Concursei\Core\Net\IpGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpGuardTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function blocked(): iterable
    {
        foreach ([
            '0.0.0.0', '10.1.2.3', '100.64.0.1', '100.127.255.254', '127.0.0.1', '127.255.255.255', '169.254.169.254',
            '172.16.0.1', '172.31.255.255', '192.0.0.8', '192.0.2.10', '192.88.99.1', '192.168.0.1', '198.18.0.1',
            '198.51.100.7', '203.0.113.9', '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255',
            '::', '::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::127.0.0.1', '64:ff9b::a9fe:a9fe', 'fc00::1', 'fd12:3456::1',
            'fe80::1', 'fec0::1', 'ff02::1', '2001::1', '2001:0:4136:e378::1', '2001:db8::1', '2002:7f00:1::1', '3fff::1',
            '100::1', '127.1', '0x7f.0.0.1', '2130706433', 'localhost', '', 'not-an-ip',
        ] as $ip) {
            yield $ip === '' ? 'vazio' : $ip => [$ip];
        }
    }

    #[DataProvider('blocked')]
    public function testRejectsNonPublicAndMalformedAddresses(string $ip): void
    {
        self::assertFalse(IpGuard::isPublic($ip), $ip);
    }

    public function testAcceptsPublicUnicast(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '200.160.2.3', '172.32.0.1', '100.128.0.1', '2804:14c::1', '2606:4700:4700::1111'] as $ip) {
            self::assertTrue(IpGuard::isPublic($ip), $ip);
        }
        self::assertTrue(IpGuard::isIpv6('2804:14c::1'));
        self::assertFalse(IpGuard::isIpv6('8.8.8.8'));
    }
}

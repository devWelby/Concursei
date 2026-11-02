<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

/**
 * Classifica endereços de destino do coletor (SSRF).
 * IPv4: tudo fora das faixas especiais (RFC 6890 e afins) é público.
 * IPv6: somente unicast global (2000::/3) menos faixas de documentação, túneis e tradução,
 * que podem embutir IPv4 interno (6to4, Teredo, NAT64). Mapeados (::ffff:a.b.c.d) ficam fora de 2000::/3.
 */
final class IpGuard
{
    private const BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const BLOCKED_V6 = [
        '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
    ];

    public static function isPublic(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 4) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }
            return true;
        }
        if (!self::inCidr($bin, '2000::/3')) {
            return false;
        }
        foreach (self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }
        return true;
    }

    public static function isIpv6(string $ip): bool
    {
        $bin = @inet_pton($ip);
        return $bin !== false && strlen($bin) === 16;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr);
        $netBin = (string) inet_pton($net);
        if (strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}

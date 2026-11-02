<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

/**
 * Utilidades de IP (IPv4/IPv6) para proxies confiáveis e política de SSRF.
 */
final class IpUtils
{
    /** @param list<string> $cidrs */
    public static function inAny(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (!str_contains($cidr, '/')) {
            $other = @inet_pton($cidr);
            return $other !== false && $other === $ipBin;
        }
        [$net, $bits] = explode('/', $cidr, 2);
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($ipBin) || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits > $maxBits) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
    }
}

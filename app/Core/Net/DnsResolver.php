<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

/** Resolução pelo DNS do sistema. O cliente valida TODOS os endereços e fixa a conexão em um deles. */
final class DnsResolver implements Resolver
{
    public function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $r) {
            if (isset($r['ip']) && is_string($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (isset($r['ipv6']) && is_string($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : [];
        }
        return array_values(array_unique($ips));
    }
}

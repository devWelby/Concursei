<?php

declare(strict_types=1);

namespace Concursei\Shared\Privacy;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;
use Concursei\Core\Security\Crypto;

/**
 * Consentimentos versionados, revogáveis e consultáveis (log append-only).
 * Termos/privacidade são separados de alertas e de marketing.
 */
class ConsentService
{
    public const PURPOSES = ['terms', 'privacy', 'age_18', 'alerts_email', 'marketing_email', 'analytics_cookies', 'ads_cookies'];

    public function __construct(private Database $db, private Clock $clock, private Crypto $crypto)
    {
    }

    public function subjectHash(string $email): string
    {
        return $this->crypto->mac('consent-subject', mb_strtolower(trim($email)));
    }

    public function record(?int $userId, ?string $subjectHash, string $purpose, string $version, bool $granted, string $source, ?string $ip = null): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Finalidade de consentimento inválida.');
        }
        $this->db->run(
            'INSERT INTO consents (user_id, subject_hash, purpose, version, granted, source, ip_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $subjectHash, $purpose, substr($version, 0, 20), $granted ? 1 : 0, substr($source, 0, 40), $ip !== null ? $this->crypto->mac('ip', $ip) : null, $this->clock->nowDb()]
        );
    }

    /** Estado atual por finalidade para um usuário. @return array<string,array{granted:bool,version:string,at:string}> */
    public function currentForUser(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT c.purpose, c.granted, c.version, c.created_at FROM consents c
             JOIN (SELECT purpose, MAX(id) AS max_id FROM consents WHERE user_id = ? GROUP BY purpose) last ON last.max_id = c.id',
            [$userId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['purpose']] = ['granted' => (bool) $r['granted'], 'version' => (string) $r['version'], 'at' => (string) $r['created_at']];
        }
        return $out;
    }
}

<?php

namespace App\Domain\Bills\Services;

/**
 * Nigerian mobile network detection by phone-number prefix.
 *
 * Single source of truth for this table — used both by KudaBillProvider
 * (to auto-resolve an airtime bill item without an explicit `biller`) and
 * by the mobile contract's GET /v1/detect-network endpoint, so the two
 * can never disagree about which network a number belongs to.
 */
final class NetworkDetector
{
    /**
     * Conservative prefix map (first 3 digits after the leading 0/234).
     * Ambiguous/unlisted prefixes deliberately resolve to null — callers
     * decline rather than risk guessing the wrong network.
     *
     * @var array<string, list<string>>
     */
    private const NETWORKS = [
        'MTN' => ['803', '806', '813', '814', '816', '903', '906', '913', '916', '700'],
        'AIRTEL' => ['802', '807', '811', '812', '817', '902', '907', '912', '917', '701'],
        'GLO' => ['805', '808', '815', '818', '819', '905', '908', '915', '918', '919'],
        '9MOBILE' => ['809', '804', '707', '709'],
    ];

    public function detect(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // Normalise to the 11-digit 0-prefixed form.
        if (str_starts_with($digits, '234')) {
            $digits = '0'.substr($digits, 3);
        }

        $prefix = substr($digits, 1, 3);

        foreach (self::NETWORKS as $network => $prefixes) {
            if (in_array($prefix, $prefixes, true)) {
                return $network;
            }
        }

        return null;
    }
}

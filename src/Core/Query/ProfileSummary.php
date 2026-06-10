<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;

/**
 * Aggregate counters over a set of profiles, for the dashboard header
 * (X critical · X error · X warning · X requests · X commands).
 */
final class ProfileSummary
{
    public function __construct(
        public readonly int $total,
        public readonly int $http,
        public readonly int $cli,
        public readonly int $critical,
        public readonly int $error,
        public readonly int $warning,
    ) {
    }

    /** @param iterable<Profile> $profiles */
    public static function of(iterable $profiles): self
    {
        $total = $http = $cli = $critical = $error = $warning = 0;
        foreach ($profiles as $profile) {
            ++$total;
            if (ProfileType::Http === $profile->type) {
                ++$http;
            } else {
                ++$cli;
            }
            $critical += $profile->levels['critical'] ?? 0;
            $error += $profile->levels['error'] ?? 0;
            $warning += $profile->levels['warning'] ?? 0;
        }

        return new self($total, $http, $cli, $critical, $error, $warning);
    }
}

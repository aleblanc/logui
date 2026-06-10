<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Model\Profile;

final class ProfileSorter
{
    /**
     * @param list<Profile> $profiles
     *
     * @return list<Profile>
     */
    public function sort(array $profiles, string $field, string $direction = 'desc'): array
    {
        if ('asc' !== $direction && 'desc' !== $direction) {
            throw new \InvalidArgumentException(sprintf('Sort direction must be "asc" or "desc", got "%s".', $direction));
        }

        $sign = 'asc' === $direction ? 1 : -1;

        usort($profiles, function (Profile $a, Profile $b) use ($field, $sign): int {
            return $sign * ($this->value($a, $field) <=> $this->value($b, $field));
        });

        return $profiles;
    }

    private function value(Profile $p, string $field): int|float|string
    {
        return match ($field) {
            'duration' => $p->durationMs,
            'mem' => $p->memPeakMb,
            'queries' => $p->queries->count,
            'status' => $p->status ?? 0,
            'label' => $p->label,
            default => $p->at->getTimestamp(),
        };
    }
}

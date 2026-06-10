<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Model\LogLevel;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;

final class ProfileFilter
{
    public function __construct(
        public readonly ?string $level = null,
        public readonly ?ProfileType $type = null,
        public readonly ?string $search = null,
        public readonly ?float $minDurationMs = null,
        public readonly ?float $minMemMb = null,
        public readonly ?string $method = null,
    ) {
        if (null !== $this->level && !\in_array(strtolower($this->level), LogLevel::all(), true)) {
            throw new \InvalidArgumentException(sprintf('Unknown log level "%s".', $this->level));
        }
    }

    public function matches(Profile $profile): bool
    {
        if (null !== $this->type && $profile->type !== $this->type) {
            return false;
        }

        if (null !== $this->method && $profile->method !== $this->method) {
            return false;
        }

        if (null !== $this->minDurationMs && $profile->durationMs < $this->minDurationMs) {
            return false;
        }

        if (null !== $this->minMemMb && $profile->memPeakMb < $this->minMemMb) {
            return false;
        }

        if (null !== $this->level && !$this->hasLevelAtLeast($profile, $this->level)) {
            return false;
        }

        if (null !== $this->search && '' !== $this->search && !$this->matchesSearch($profile, $this->search)) {
            return false;
        }

        return true;
    }

    private function hasLevelAtLeast(Profile $profile, string $level): bool
    {
        // Uses the level-count map (always present), not the records (not stored in telemetry mode).
        $threshold = LogLevel::severity($level);
        foreach ($profile->levels as $name => $count) {
            if ($count > 0 && LogLevel::severity($name) >= $threshold) {
                return true;
            }
        }

        return false;
    }

    private function matchesSearch(Profile $profile, string $needle): bool
    {
        $needle = mb_strtolower($needle);
        if (str_contains(mb_strtolower($profile->label), $needle)) {
            return true;
        }
        foreach ($profile->records as $record) {
            if (str_contains(mb_strtolower($record->message), $needle)) {
                return true;
            }
        }

        return false;
    }
}

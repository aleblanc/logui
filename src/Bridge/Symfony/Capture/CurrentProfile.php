<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Capture;

use Aleblanc\LogUi\Core\Capture\ProfileContext;
use Aleblanc\LogUi\Core\Model\Profile;

/**
 * Holds the ProfileContext for the request/command currently being profiled.
 *
 * All mutating methods are null-safe: a log record or exception emitted while no
 * profile is active is silently ignored, so capture never breaks the host app.
 */
final class CurrentProfile
{
    private ?ProfileContext $context = null;

    public function begin(ProfileContext $context): void
    {
        $this->context = $context;
    }

    public function isActive(): bool
    {
        return null !== $this->context;
    }

    public function setLabel(string $label): void
    {
        $this->context?->setLabel($label);
    }

    public function setMethod(?string $method): void
    {
        $this->context?->setMethod($method);
    }

    /** @param array<array-key,mixed> $context */
    public function addRecord(string $level, string $channel, string $message, array $context): void
    {
        $this->context?->addRecord($level, $channel, $message, $context);
    }

    public function recordQuery(string $sql, float $ms): void
    {
        $this->context?->recordQuery($sql, $ms);
    }

    public function setException(\Throwable $e): void
    {
        $this->context?->setException($e);
    }

    /** Finalizes and clears the active profile, or returns null if none was active. */
    public function finish(?int $status): ?Profile
    {
        if (null === $this->context) {
            return null;
        }

        $profile = $this->context->finish($status);
        $this->context = null;

        return $profile;
    }
}

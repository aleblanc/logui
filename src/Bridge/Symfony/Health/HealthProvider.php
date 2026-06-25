<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Health;

interface HealthProvider
{
    /**
     * Returns the available server-health sections. Each section is empty/null when the
     * underlying data is not available on this host, so the UI can simply skip it.
     *
     * @return array<string, mixed>
     */
    public function getData(): array;
}

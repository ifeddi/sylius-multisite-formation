<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Provisioning;

/** Ce que l'ouverture du site a changé (ou changerait en simulation), et les avertissements. */
final class ProvisioningReport
{
    /** @var list<array{string, string, string}> */
    private array $changes = [];

    /** @var list<string> */
    private array $warnings = [];

    public function change(string $action, string $subject, string $detail): void
    {
        $this->changes[] = [$action, $subject, $detail];
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @return list<array{string, string, string}> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function hasChanges(): bool
    {
        return [] !== $this->changes;
    }
}

<?php

declare(strict_types=1);

namespace App\Formation\Module03Supplier;

/**
 * Formation M3 : logique métier isolée dans un service testable.
 * Le listener d'événement reste mince et délègue ici (les listeners ne sont pas couverts
 * par la promesse de compatibilité de Sylius).
 */
final class SupplierCodeNormalizer
{
    public function normalize(string $code): string
    {
        $code = strtolower(trim($code));
        $code = (string) preg_replace('/[^a-z0-9]+/', '_', $code);

        return trim($code, '_');
    }
}

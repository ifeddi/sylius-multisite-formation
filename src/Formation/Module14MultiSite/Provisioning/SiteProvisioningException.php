<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Provisioning;

/** Erreur(s) bloquante(s) lors de la lecture d'une définition de site ou de l'ouverture du site. */
final class SiteProvisioningException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors, ?\Throwable $previous = null)
    {
        parent::__construct(implode(\PHP_EOL, $errors), 0, $previous);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}

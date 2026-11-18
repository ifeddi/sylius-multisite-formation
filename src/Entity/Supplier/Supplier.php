<?php

declare(strict_types=1);

namespace App\Entity\Supplier;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Resource\Model\ResourceInterface;

/**
 * Formation M3 : ressource personnalisée « Fournisseur ».
 * Déclarée comme ressource Sylius dans config/packages/formation_m03_supplier.yaml.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_supplier')]
class Supplier implements ResourceInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    /** @phpstan-ignore property.unusedType (l'identifiant est affecté par Doctrine) */
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private ?string $code = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(name: 'contact_email', type: 'string', length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): void
    {
        $this->code = $code;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): void
    {
        $this->contactEmail = $contactEmail;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}

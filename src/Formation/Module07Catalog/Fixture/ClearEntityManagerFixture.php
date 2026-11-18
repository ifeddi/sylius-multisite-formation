<?php

declare(strict_types=1);

namespace App\Formation\Module07Catalog\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;

/**
 * Formation M14 : détache toutes les entités de l'EntityManager entre deux fixtures.
 *
 * Pourquoi : la fixture « order » du cœur charge les produits avec la seule traduction de la langue
 * du site (jointure filtrée). Sur un projet multi-langues, enchaîner des commandes de sites de langues
 * différentes dans le même processus réutilise un produit dont les traductions sont incomplètes, et
 * Sylius tente alors de créer une traduction vide (erreur « Column 'name' cannot be null »).
 * Vider l'EntityManager entre les lots de commandes force une relecture complète en base.
 */
final class ClearEntityManagerFixture extends AbstractFixture
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getName(): string
    {
        return 'entity_manager_clear';
    }

    /** @param array<string, mixed> $options */
    public function load(array $options): void
    {
        $this->entityManager->clear();
    }
}

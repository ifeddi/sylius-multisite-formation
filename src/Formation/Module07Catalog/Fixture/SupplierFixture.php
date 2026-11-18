<?php

declare(strict_types=1);

namespace App\Formation\Module07Catalog\Fixture;

use App\Entity\Supplier\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Formation M3 / M7 : fixture « supplier » qui crée des fournisseurs de démonstration.
 * Une fixture personnalisée s'enregistre automatiquement (autoconfiguration de FixtureInterface)
 * puis s'utilise dans une suite : voir config/packages/formation_m14_multisite_fixtures.yaml.
 */
final class SupplierFixture extends AbstractFixture
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getName(): string
    {
        return 'supplier';
    }

    /** @param array{custom?: list<array{code: string, name: string, contact_email?: string, enabled?: bool}>} $options */
    public function load(array $options): void
    {
        foreach ($options['custom'] ?? [] as $data) {
            $supplier = new Supplier();
            $supplier->setCode($data['code']);
            $supplier->setName($data['name']);
            $supplier->setContactEmail($data['contact_email'] ?? null);
            $supplier->setEnabled($data['enabled'] ?? true);

            $this->entityManager->persist($supplier);
        }

        $this->entityManager->flush();
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('custom')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('code')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('contact_email')->end()
                            ->booleanNode('enabled')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}

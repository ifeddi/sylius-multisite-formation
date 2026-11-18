<?php

declare(strict_types=1);

namespace App\Formation\Module07Catalog\Fixture;

use App\Entity\Product\Product;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Formation M2 / M6 / M7 : complète les produits créés par la fixture « product » du cœur.
 *  - renseigne le champ « origin » (module 2) ;
 *  - traduit le nom, le slug et les descriptions par langue (modules 6 et 14) : la fixture du cœur
 *    donne le même nom à toutes les langues.
 * À placer après la fixture « product » dans la suite.
 */
final class ProductEnrichmentFixture extends AbstractFixture
{
    private const DESCRIPTIONS = [
        'en_US' => ['short' => '%s. Origin: %s.', 'long' => '%s. Origin: %s. Professional packaging for resale and catering.'],
        'fr_FR' => ['short' => '%s. Origine : %s.', 'long' => '%s. Origine : %s. Conditionnement professionnel pour la revente et la restauration.'],
        'de_DE' => ['short' => '%s. Herkunft: %s.', 'long' => '%s. Herkunft: %s. Gewerbliche Verpackung für Wiederverkauf und Gastronomie.'],
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getName(): string
    {
        return 'product_enrichment';
    }

    /** @param array{products?: array<string, array{origin: string, names: array<string, string>}>} $options */
    public function load(array $options): void
    {
        $repository = $this->entityManager->getRepository(Product::class);

        foreach ($options['products'] ?? [] as $code => $data) {
            $product = $repository->findOneBy(['code' => (string) $code]);
            if (!$product instanceof Product) {
                throw new \RuntimeException(sprintf('Produit "%s" introuvable : la fixture "product" doit précéder "product_enrichment".', $code));
            }

            $product->setOrigin($data['origin']);

            foreach ($data['names'] as $locale => $name) {
                $translation = $product->getTranslation($locale);
                $texts = self::DESCRIPTIONS[$locale] ?? self::DESCRIPTIONS['en_US'];

                $translation->setName($name);
                $translation->setSlug((new AsciiSlugger($locale))->slug($name)->lower()->toString());
                $translation->setShortDescription(sprintf($texts['short'], $name, $data['origin']));
                $translation->setDescription(sprintf($texts['long'], $name, $data['origin']));
            }
        }

        $this->entityManager->flush();

        // Détache les entités chargées : les fixtures suivantes (commandes) relisent les produits
        // en base avec leurs traductions à jour, au lieu de réutiliser des objets déjà en mémoire.
        $this->entityManager->clear();
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->arrayNode('products')
                    ->useAttributeAsKey('code')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('origin')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('names')
                                ->useAttributeAsKey('locale')
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}

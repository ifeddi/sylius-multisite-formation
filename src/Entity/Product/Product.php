<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\Product as BaseProduct;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\MolliePlugin\Entity\ProductInterface;
use Sylius\MolliePlugin\Entity\ProductTrait;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_product')]
class Product extends BaseProduct implements ProductInterface
{
    use ProductTrait;

    /** Formation M2 : pays ou région d'origine du produit (champ ajouté par surcharge de l'entité). */
    #[ORM\Column(name: 'origin', type: 'string', length: 100, nullable: true)]
    private ?string $origin = null;

    public function getOrigin(): ?string
    {
        return $this->origin;
    }

    public function setOrigin(?string $origin): void
    {
        $this->origin = $origin;
    }

    protected function createTranslation(): ProductTranslationInterface
    {
        return new ProductTranslation();
    }
}

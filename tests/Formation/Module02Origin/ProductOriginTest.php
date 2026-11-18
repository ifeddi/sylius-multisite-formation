<?php

declare(strict_types=1);

namespace App\Tests\Formation\Module02Origin;

use App\Entity\Product\Product;
use PHPUnit\Framework\TestCase;

/** Formation M2 : test du champ « origin » ajouté par surcharge de l'entité Product. */
final class ProductOriginTest extends TestCase
{
    public function testOriginIsNullByDefault(): void
    {
        self::assertNull((new Product())->getOrigin());
    }

    public function testOriginCanBeSetAndRead(): void
    {
        $product = new Product();
        $product->setOrigin('Italie');

        self::assertSame('Italie', $product->getOrigin());
    }

    public function testOriginCanBeReset(): void
    {
        $product = new Product();
        $product->setOrigin('France');
        $product->setOrigin(null);

        self::assertNull($product->getOrigin());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Formation\Module03Supplier;

use App\Entity\Supplier\Supplier;
use App\Formation\Module03Supplier\EventListener\SupplierCodeListener;
use App\Formation\Module03Supplier\SupplierCodeNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;

/** Formation M3 : le service de normalisation et le listener mince qui l'appelle. */
final class SupplierCodeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function codes(): iterable
    {
        yield 'majuscules' => ['ACME', 'acme'];
        yield 'espaces' => ['  Acme Corp  ', 'acme_corp'];
        yield 'accents et symboles' => ['Café & Co!', 'caf_co'];
        yield 'déjà propre' => ['acme_corp', 'acme_corp'];
    }

    #[DataProvider('codes')]
    public function testNormalizer(string $input, string $expected): void
    {
        self::assertSame($expected, (new SupplierCodeNormalizer())->normalize($input));
    }

    public function testListenerNormalizesTheCodeOfASupplier(): void
    {
        $supplier = new Supplier();
        $supplier->setCode('Acme Corp');

        (new SupplierCodeListener(new SupplierCodeNormalizer()))(new ResourceControllerEvent($supplier));

        self::assertSame('acme_corp', $supplier->getCode());
    }

    public function testListenerIgnoresOtherResources(): void
    {
        $other = new \stdClass();

        (new SupplierCodeListener(new SupplierCodeNormalizer()))(new ResourceControllerEvent($other));

        self::assertEquals(new \stdClass(), $other);
    }
}

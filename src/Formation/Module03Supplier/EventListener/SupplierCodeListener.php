<?php

declare(strict_types=1);

namespace App\Formation\Module03Supplier\EventListener;

use App\Entity\Supplier\Supplier;
use App\Formation\Module03Supplier\SupplierCodeNormalizer;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Formation M3 : événements du contrôleur de ressource.
 * Nom d'événement = <application>.<ressource>.<action> (ici app.supplier.pre_create / pre_update).
 */
final class SupplierCodeListener
{
    public function __construct(private readonly SupplierCodeNormalizer $normalizer)
    {
    }

    #[AsEventListener(event: 'app.supplier.pre_create')]
    #[AsEventListener(event: 'app.supplier.pre_update')]
    public function __invoke(ResourceControllerEvent $event): void
    {
        $supplier = $event->getSubject();

        if (!$supplier instanceof Supplier || null === $supplier->getCode()) {
            return;
        }

        $supplier->setCode($this->normalizer->normalize($supplier->getCode()));
    }
}

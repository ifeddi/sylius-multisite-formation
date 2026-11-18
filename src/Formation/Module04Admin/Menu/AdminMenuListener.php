<?php

declare(strict_types=1);

namespace App\Formation\Module04Admin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Formation M4 : ajout d'une entrée « Fournisseurs » au menu d'administration. */
final class AdminMenuListener
{
    #[AsEventListener(event: 'sylius.menu.admin.main')]
    public function __invoke(MenuBuilderEvent $event): void
    {
        $formation = $event->getMenu()
            ->addChild('app_formation')
            ->setLabel('app.menu.formation')
            ->setLabelAttribute('icon', 'tabler:school')
        ;

        $formation
            ->addChild('app_suppliers', ['route' => 'app_admin_supplier_index', 'extras' => ['routes' => [
                ['route' => 'app_admin_supplier_create'],
                ['route' => 'app_admin_supplier_update'],
            ]]])
            ->setLabel('app.ui.suppliers')
            ->setLabelAttribute('icon', 'tabler:truck')
        ;
    }
}

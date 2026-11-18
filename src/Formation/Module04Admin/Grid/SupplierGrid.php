<?php

declare(strict_types=1);

namespace App\Formation\Module04Admin\Grid;

use App\Entity\Supplier\Supplier;
use Sylius\Bundle\GridBundle\Builder\Action\CreateAction;
use Sylius\Bundle\GridBundle\Builder\Action\DeleteAction;
use Sylius\Bundle\GridBundle\Builder\Action\UpdateAction;
use Sylius\Bundle\GridBundle\Builder\Field\StringField;
use Sylius\Bundle\GridBundle\Builder\Filter\StringFilter;
use Sylius\Bundle\GridBundle\Builder\GridBuilderInterface;
use Sylius\Component\Grid\Attribute\AsGrid;

/**
 * Formation M4 : grille d'administration des fournisseurs (même approche que les grilles du cœur Sylius).
 */
#[AsGrid(resourceClass: Supplier::class, name: self::NAME)]
final class SupplierGrid
{
    public const NAME = 'app_admin_supplier';

    public function __invoke(GridBuilderInterface $gridBuilder): void
    {
        $gridBuilder
            ->setLimits([10, 25, 50])
            ->addOrderBy('name', 'asc')
            ->withFields(
                StringField::create('code')->setLabel('sylius.ui.code')->setSortable(true),
                StringField::create('name')->setLabel('sylius.ui.name')->setSortable(true),
                StringField::create('contactEmail')->setLabel('app.ui.contact_email'),
            )
            ->withFilters(
                StringFilter::create('search', ['code', 'name'])
                    ->setLabel('sylius.ui.search')
                    ->addFormOption('type', StringFilter::TYPE_CONTAINS),
            )
            ->withMainActions(CreateAction::create())
            ->withItemActions(UpdateAction::create(), DeleteAction::create())
            ->withBulkActions(DeleteAction::create())
        ;
    }
}

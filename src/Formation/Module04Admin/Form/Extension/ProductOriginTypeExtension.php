<?php

declare(strict_types=1);

namespace App\Formation\Module04Admin\Form\Extension;

use Sylius\Bundle\ProductBundle\Form\Type\ProductType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Formation M4 : ajoute le champ « origin » au formulaire produit (admin).
 * On étend le type de formulaire parent de Sylius, comme le fait ProductTypeExtension du cœur.
 */
final class ProductOriginTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('origin', TextType::class, [
            'label' => 'app.ui.origin',
            'required' => false,
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ProductType::class];
    }
}

<?php

declare(strict_types=1);

namespace App\Formation\Module04Admin\Form\Type;

use App\Entity\Supplier\Supplier;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formation M4 : formulaire d'administration de la ressource Fournisseur. */
final class SupplierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, ['label' => 'sylius.ui.code'])
            ->add('name', TextType::class, ['label' => 'sylius.ui.name'])
            ->add('contactEmail', EmailType::class, ['label' => 'app.ui.contact_email', 'required' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'sylius.ui.enabled', 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', Supplier::class);
    }
}

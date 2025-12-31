<?php

namespace App\Form\Admin;

use App\Entity\Categories;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProductsBarcodeImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('search', TextType::class, [
                'label' => 'Recherche (nom, marque, etc.)',
                'mapped' => false,
                'required' => false,
                'help' => 'Tapez quelques lettres puis sélectionnez une suggestion : le code-barres sera rempli automatiquement si disponible.',
            ])
            ->add('name', TextType::class, [
                'label' => 'Nom (pré-rempli)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'readonly' => true,
                ],
            ])
            ->add('brand', TextType::class, [
                'label' => 'Marque (pré-remplie)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'readonly' => true,
                ],
            ])
            ->add('color', TextType::class, [
                'label' => 'Couleur (pré-remplie)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'readonly' => true,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description (pré-remplie)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'readonly' => true,
                    'rows' => 4,
                ],
            ])
            ->add('barcode', TextType::class, [
                'label' => 'Code-barres (EAN/UPC)',
                'required' => false,
                'help' => 'Collez/scannez un code-barres. Le système tente une recherche via une API gratuite et évite les doublons.',
            ])
            ->add('externalId', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->add('externalSource', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->add('categories', EntityType::class, [
                'class' => Categories::class,
                'choice_label' => 'name',
                'label' => 'Catégorie',
                'attr' => [
                    'data-ea-widget' => 'ea-autocomplete',
                ],
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Prix',
                'currency' => 'EUR',
                'help' => 'Prix public en euros (sera stocké en centimes).',
            ])
            ->add('stock', IntegerType::class, [
                'label' => 'Stock',
                'data' => 0,
            ])
            ->add('updateIfExists', CheckboxType::class, [
                'label' => 'Mettre à jour si le produit existe déjà',
                'required' => false,
                'help' => 'Si coché, complète les champs manquants et ajoute les nouvelles images sans dupliquer.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
        ]);
    }
}

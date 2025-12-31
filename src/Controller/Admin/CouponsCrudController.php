<?php

namespace App\Controller\Admin;

use App\Entity\Coupons;
use App\Entity\CouponsTypes;
use App\Entity\Products;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CouponsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Coupons::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('code', 'Code Promo')
                ->setHelp('Code unique de 10 caractères maximum')
                ->setMaxLength(10),
            TextareaField::new('description', 'Description')
                ->setHelp('Description de la promotion'),
            AssociationField::new('coupons_types', 'Type de coupon')
                ->setRequired(true)
                ->setHelp('Pourcentage ou montant fixe'),
            IntegerField::new('discount', 'Réduction')
                ->setHelp('Montant en centimes pour fixe, ou pourcentage pour %'),
            AssociationField::new('products', 'Produit')
                ->setRequired(true)
                ->setHelp('Produit concerné par cette promotion'),
            IntegerField::new('max_usage', 'Utilisations max')
                ->setHelp('Nombre maximum d\'utilisations'),
            DateTimeField::new('validity', 'Date de validité')
                ->setHelp('Date jusqu\'à laquelle le coupon est valide'),
            BooleanField::new('is_valid', 'Actif')
                ->setHelp('Activer/désactiver le coupon'),
        ];
    }
}

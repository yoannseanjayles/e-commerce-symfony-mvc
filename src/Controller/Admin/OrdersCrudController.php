<?php

namespace App\Controller\Admin;

use App\Entity\Orders;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class OrdersCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Orders::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Commande')
            ->setEntityLabelInPlural('Commandes')
            // CreatedAtTrait uses the mapped field name "created_at".
            ->setDefaultSort(['created_at' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('reference', 'Référence');

        // Basic user association (clickable)
        yield AssociationField::new('users', 'Client')->onlyOnIndex();

        yield MoneyField::new('total', 'Total')
            ->setCurrency('EUR')
            ->setStoredAsCents();

        yield DateTimeField::new('createdAt', 'Créée le')
            ->setSortable(false)
            ->onlyOnIndex();

        if ($pageName === Crud::PAGE_DETAIL) {
            yield TextField::new('userFullName', 'Client (nom)')->setRequired(false);
            yield TextField::new('userEmail', 'Client (email)')->setRequired(false);
            yield TextField::new('userAddressLine', 'Adresse')->setRequired(false);

            yield AssociationField::new('coupons', 'Coupon')->setRequired(false);

            yield CollectionField::new('ordersDetails', 'Panier')
                ->setTemplatePath('admin/field/order_details_table.html.twig');
        }
    }
}

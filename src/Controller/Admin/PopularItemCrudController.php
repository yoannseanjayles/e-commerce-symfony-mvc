<?php

namespace App\Controller\Admin;

use App\Entity\PopularItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Validator\Constraints\Image;

class PopularItemCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return PopularItem::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Article populaire')
            ->setEntityLabelInPlural('Articles populaires')
            ->setDefaultSort(['position' => 'ASC'])
            ->setPageTitle('index', 'Articles populaires (page d\'accueil)');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield IntegerField::new('position', 'Position')
            ->setHelp('Ordre d\'affichage (1 = premier)');
        yield TextField::new('title', 'Titre')
            ->setHelp('Ex: Glasses, Watches, Jackets, Clothes');
        yield ImageField::new('image', 'Image')
            ->setBasePath('assets/uploads/')
            ->setUploadDir('public/assets/uploads/')
            ->setUploadedFileNamePattern('[randomhash].[extension]')
            ->setFormTypeOption('constraints', [
                new Image([
                    'maxSize' => '3M',
                    'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                    'maxWidth' => 6000,
                    'maxHeight' => 6000,
                ]),
            ])
            ->setHelp('Image de l\'article (recommandé: 400x500px)');
        yield AssociationField::new('category', 'Catégorie')
            ->setHelp('Catégorie vers laquelle rediriger au clic (optionnel)');
        yield BooleanField::new('isActive', 'Actif');
    }
}

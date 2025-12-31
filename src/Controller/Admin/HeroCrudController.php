<?php

namespace App\Controller\Admin;

use App\Entity\Hero;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\Validator\Constraints\Image;

class HeroCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Hero::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Hero Slide')
            ->setEntityLabelInPlural('Hero Slides')
            ->setDefaultSort(['position' => 'ASC'])
            ->setPageTitle('index', 'Gestion des Slides Hero');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('title', 'Titre')
                ->setHelp('Titre principal du slide (ex: "fashion changing always")'),
            TextField::new('subtitle', 'Sous-titre')
                ->setHelp('Sous-titre optionnel')
                ->hideOnIndex(),
            TextField::new('buttonText', 'Texte du bouton')
                ->setHelp('Texte affiché sur le bouton (ex: "Shop Now")'),
            TextField::new('buttonLink', 'Lien du bouton')
                ->setHelp('URL ou route Symfony (ex: "/produits" ou "products_index").')
                ->setFormTypeOption('attr', ['data-route-picker' => '1']),
            ImageField::new('backgroundImage', 'Image de fond')
                ->setBasePath('assets/uploads/')
                ->setUploadDir('public/assets/uploads/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setFormTypeOption('constraints', [
                    new Image([
                        'maxSize' => '4M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'maxWidth' => 6000,
                        'maxHeight' => 6000,
                    ]),
                ])
                ->setHelp('Image de fond du slide (recommandé: 1920x800px)'),
            IntegerField::new('position', 'Position')
                ->setHelp('Ordre d\'affichage du slide (1, 2, 3...)'),
            BooleanField::new('isActive', 'Actif')
                ->setHelp('Afficher ou masquer ce slide'),
        ];
    }
}

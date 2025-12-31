<?php

namespace App\Controller\Admin;

use App\Entity\AboutPage;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use Symfony\Component\Validator\Constraints\Image;

class AboutPageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AboutPage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Page À propos')
            ->setEntityLabelInPlural('Page À propos')
            ->setPageTitle('index', 'Modifier la page À propos')
            ->setPageTitle('edit', 'Modifier la page À propos');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Section 1')->setIcon('fa fa-info-circle'),
            TextField::new('section1Title', 'Titre de la section 1')
                ->setHelp('Ex: Notre Histoire'),
            TextareaField::new('section1Text', 'Texte de la section 1')
                ->setHelp('Description de votre histoire'),
            ImageField::new('section1Image', 'Image de la section 1')
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
                ->setHelp('Image illustrant votre histoire (recommandé: 1200x600px)'),
            
            FormField::addPanel('Section 2')->setIcon('fa fa-rocket'),
            TextField::new('section2Title', 'Titre de la section 2')
                ->setHelp('Ex: Le Début de l\'Aventure'),
            TextareaField::new('section2Text', 'Texte de la section 2')
                ->setHelp('Racontez vos débuts'),
            ImageField::new('section2Image', 'Image de la section 2')
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
                ->setHelp('Image illustrant vos débuts (recommandé: 1200x600px)'),
            
            FormField::addPanel('Section 3')->setIcon('fa fa-calendar'),
            TextField::new('section3Title', 'Titre de la section 3')
                ->setHelp('Ex: Aujourd\'hui'),
            TextareaField::new('section3Text', 'Texte de la section 3')
                ->setHelp('Parlez de votre situation actuelle'),
            
            FormField::addPanel('Services (bas de page)')->setIcon('fa fa-cogs'),
            TextField::new('service1Title', 'Service 1 - Titre')
                ->setHelp('Ex: Livraison Rapide & Gratuite'),
            TextareaField::new('service1Text', 'Service 1 - Description')
                ->setHelp('Ex: Livraison gratuite sur toutes les commandes')
                ->setNumOfRows(2),
            
            TextField::new('service2Title', 'Service 2 - Titre')
                ->setHelp('Ex: Paiement Sécurisé'),
            TextareaField::new('service2Text', 'Service 2 - Description')
                ->setHelp('Ex: Transactions 100% sécurisées')
                ->setNumOfRows(2),
            
            TextField::new('service3Title', 'Service 3 - Titre')
                ->setHelp('Ex: Retours Faciles'),
            TextareaField::new('service3Text', 'Service 3 - Description')
                ->setHelp('Ex: Retours gratuits sous 30 jours')
                ->setNumOfRows(2),
            
            TextField::new('service4Title', 'Service 4 - Titre')
                ->setHelp('Ex: Support 24/7'),
            TextareaField::new('service4Text', 'Service 4 - Description')
                ->setHelp('Ex: Service client disponible à tout moment')
                ->setNumOfRows(2),
        ];
    }
}

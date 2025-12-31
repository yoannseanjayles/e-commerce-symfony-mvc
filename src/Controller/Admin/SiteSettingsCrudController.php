<?php

namespace App\Controller\Admin;

use App\Entity\SiteSettings;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Validator\Constraints\Image;

class SiteSettingsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SiteSettings::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('Identité du site')->setIcon('fa fa-globe'),
            TextField::new('siteTitle', 'Titre de l\'onglet')
                ->setHelp('Titre affiché dans l\'onglet du navigateur (SEO)'),
            ImageField::new('siteFavicon', 'Favicon')
                ->setBasePath('assets/uploads/')
                ->setUploadDir('public/assets/uploads/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setFormTypeOption('constraints', [
                    new Image([
                        'maxSize' => '1M',
                        'mimeTypes' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
                        'maxWidth' => 512,
                        'maxHeight' => 512,
                    ]),
                ])
                ->setHelp('Icône de l\'onglet du navigateur (format .ico ou .png, 32x32px recommandé)'),
            
            FormField::addPanel('Informations générales')->setIcon('fa fa-info-circle'),
            TextField::new('siteName', 'Nom du site')
                ->setHelp('Le nom de votre site e-commerce'),
            TextareaField::new('siteDescription', 'Description')
                ->setHelp('Description courte de votre entreprise (affichée dans le footer)'),
            
            FormField::addPanel('Contact')->setIcon('fa fa-phone'),
            TextField::new('siteEmail', 'Email')
                ->setHelp('Adresse email de contact'),
            TextField::new('sitePhone', 'Téléphone')
                ->setHelp('Numéro de téléphone de contact'),
            TextareaField::new('siteAddress', 'Adresse')
                ->setHelp('Adresse complète de votre entreprise'),
            
            FormField::addPanel('Réseaux sociaux')->setIcon('fab fa-facebook'),
            TextField::new('facebookUrl', 'Facebook')
                ->setHelp('URL complète de votre page Facebook'),
            TextField::new('twitterUrl', 'Twitter')
                ->setHelp('URL complète de votre compte Twitter'),
            TextField::new('instagramUrl', 'Instagram')
                ->setHelp('URL complète de votre compte Instagram'),
            TextField::new('tiktokUrl', 'TikTok')
                ->setHelp('URL complète de votre compte TikTok'),
            TextField::new('youtubeUrl', 'YouTube')
                ->setHelp('URL complète de votre chaîne YouTube'),
            TextField::new('pinterestUrl', 'Pinterest')
                ->setHelp('URL complète de votre compte Pinterest'),
            
            FormField::addPanel('Logos')->setIcon('fa fa-image'),
            ImageField::new('logoHeader', 'Logo Header')
                ->setBasePath('assets/uploads/')
                ->setUploadDir('public/assets/uploads/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setFormTypeOption('constraints', [
                    new Image([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'maxWidth' => 4000,
                        'maxHeight' => 4000,
                    ]),
                ])
                ->setHelp('Logo affiché dans l\'en-tête du site (recommandé: 200x50px)'),
            ImageField::new('logoFooter', 'Logo Footer')
                ->setBasePath('assets/uploads/')
                ->setUploadDir('public/assets/uploads/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setFormTypeOption('constraints', [
                    new Image([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'maxWidth' => 4000,
                        'maxHeight' => 4000,
                    ]),
                ])
                ->setHelp('Logo affiché dans le pied de page (recommandé: 200x50px)'),
            ImageField::new('logoLoader', 'Logo Loader')
                ->setBasePath('assets/uploads/')
                ->setUploadDir('public/assets/uploads/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setFormTypeOption('constraints', [
                    new Image([
                        'maxSize' => '2M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'maxWidth' => 4000,
                        'maxHeight' => 4000,
                    ]),
                ])
                ->setHelp('Logo affiché pendant le chargement de la page'),

            FormField::addPanel('Maintenance')->setIcon('fa fa-tools'),
            BooleanField::new('maintenanceEnabled', 'Afficher la page maintenance')
                ->setHelp('Si activé, le site est accessible uniquement aux admins connectés.'),

            FormField::addPanel('Paiement')->setIcon('fa fa-credit-card'),
            BooleanField::new('stripeEnabled', 'Activer le tunnel Stripe')
                ->setHelp('Si activé, la validation du panier redirige vers Stripe Checkout.'),

            FormField::addPanel('Clés API (override)')->setIcon('fa fa-key'),
            TextField::new('stripeSecretKeyOverride', 'Stripe secret key (override)')
                ->onlyOnForms()
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('required', false)
                ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
                ->setHelp('Laisser vide pour utiliser STRIPE_SECRET_KEY (variable d’environnement).'),
            TextField::new('stripeWebhookSecretOverride', 'Stripe webhook secret (override)')
                ->onlyOnForms()
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('required', false)
                ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
                ->setHelp('Laisser vide pour utiliser STRIPE_WEBHOOK_SECRET (variable d’environnement).'),
            TextField::new('openAiApiKeyOverride', 'OpenAI API key (override)')
                ->onlyOnForms()
                ->setFormType(PasswordType::class)
                ->setFormTypeOption('required', false)
                ->setFormTypeOption('attr', ['autocomplete' => 'new-password'])
                ->setHelp('Laisser vide pour utiliser OPENAI_API_KEY (variable d’environnement).'),
        ];
    }
}

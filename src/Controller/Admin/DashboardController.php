<?php

namespace App\Controller\Admin;

use App\Entity\AboutPage;
use App\Entity\Categories;
use App\Entity\ContactMessage;
use App\Entity\PopularItem;
use App\Entity\Coupons;
use App\Entity\CouponsTypes;
use App\Entity\Hero;
use App\Entity\Images;
use App\Entity\Orders;
use App\Entity\ProductVariant;
use App\Entity\Products;
use App\Entity\SiteSettings;
use App\Entity\Users;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private AdminUrlGenerator $adminUrlGenerator
    ) {
    }

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $url = $this->adminUrlGenerator
            ->setController(ProductsCrudController::class)
            ->setAction('index')
            ->generateUrl();
        
        return $this->redirect($url);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('E-Commerce Admin')
            ->setFaviconPath('favicon.ico');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToRoute('Voir le site', 'fas fa-eye', 'shionhouse_index');
        
        yield MenuItem::section('Catalogue');
        yield MenuItem::linkToCrud('Produits', 'fas fa-shopping-cart', Products::class);
        $importUrl = $this->adminUrlGenerator
            ->setController(ProductsCrudController::class)
            ->setAction('import')
            ->generateUrl();
        yield MenuItem::linkToUrl('Importer produit (code-barres)', 'fas fa-barcode', $importUrl);
        yield MenuItem::linkToCrud('Catégories', 'fas fa-list', Categories::class);
        yield MenuItem::linkToCrud('Variantes', 'fas fa-tags', ProductVariant::class);
        yield MenuItem::linkToCrud('Images', 'fas fa-images', Images::class);
        
        yield MenuItem::section('Commandes');
        yield MenuItem::linkToCrud('Commandes', 'fas fa-clipboard-list', Orders::class);
        
        yield MenuItem::section('Promotions');
        yield MenuItem::linkToCrud('Coupons', 'fas fa-ticket-alt', Coupons::class);
        yield MenuItem::linkToCrud('Types de coupons', 'fas fa-tag', CouponsTypes::class);
        
        yield MenuItem::section('Utilisateurs');
        yield MenuItem::linkToCrud('Utilisateurs', 'fas fa-user', Users::class);
        
        yield MenuItem::section('Communication');
        yield MenuItem::linkToCrud('Messages de contact', 'fas fa-envelope', ContactMessage::class);
        
        yield MenuItem::section('Contenu');
        yield MenuItem::linkToCrud('Hero Slides', 'fas fa-images', Hero::class);
        yield MenuItem::linkToCrud('Articles populaires', 'fas fa-star', PopularItem::class);
        yield MenuItem::linkToCrud('Page À propos', 'fas fa-info-circle', AboutPage::class);
        
        yield MenuItem::section('Configuration');
        $aiCostControlsUrl = $this->adminUrlGenerator
            ->setController(AiCostControlsCrudController::class)
            ->setAction('index')
            ->generateUrl();
        yield MenuItem::linkToUrl('IA — Coûts & limites', 'fas fa-robot', $aiCostControlsUrl);
        yield MenuItem::linkToCrud('Paramètres du site', 'fas fa-cog', SiteSettings::class);
    }
}

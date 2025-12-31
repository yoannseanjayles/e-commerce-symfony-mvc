<?php

namespace App\Controller;

use App\Repository\ProductsRepository;
use App\Repository\CategoriesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', defaults: ['_format' => 'xml'])]
    public function index(ProductsRepository $productsRepository, CategoriesRepository $categoriesRepository): Response
    {
        // Get hostname from request
        $hostname = $this->getParameter('app.hostname') ?? 'https://votresite.com';
        
        // Get all published products
        $products = $productsRepository->findAll();
        
        // Get all active categories
        $categories = $categoriesRepository->findAll();
        
        $urls = [];
        
        // Static pages
        $urls[] = [
            'loc' => $hostname . '/',
            'lastmod' => (new \DateTime())->format('Y-m-d'),
            'changefreq' => 'daily',
            'priority' => '1.0'
        ];
        
        $urls[] = [
            'loc' => $hostname . '/contact',
            'lastmod' => (new \DateTime())->format('Y-m-d'),
            'changefreq' => 'monthly',
            'priority' => '0.9'
        ];
        
        $urls[] = [
            'loc' => $hostname . '/about',
            'lastmod' => (new \DateTime())->format('Y-m-d'),
            'changefreq' => 'monthly',
            'priority' => '0.7'
        ];
        
        // Products
        foreach ($products as $product) {
            $urls[] = [
                'loc' => $hostname . '/products/' . $product->getSlug(),
                'lastmod' => $product->getUpdatedAt() ? $product->getUpdatedAt()->format('Y-m-d') : (new \DateTime())->format('Y-m-d'),
                'changefreq' => 'weekly',
                'priority' => '0.8'
            ];
        }
        
        // Categories
        foreach ($categories as $category) {
            $urls[] = [
                'loc' => $hostname . '/categories/' . $category->getSlug(),
                'lastmod' => (new \DateTime())->format('Y-m-d'),
                'changefreq' => 'weekly',
                'priority' => '0.7'
            ];
        }
        
        $response = $this->render('sitemap/index.xml.twig', [
            'urls' => $urls,
        ]);
        
        $response->headers->set('Content-Type', 'application/xml');
        
        return $response;
    }
}

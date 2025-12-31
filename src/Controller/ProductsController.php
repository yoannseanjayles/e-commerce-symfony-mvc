<?php

namespace App\Controller;

use App\Entity\Products;
use App\Repository\CategoriesRepository;
use App\Repository\CouponsRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\ProductsRepository;
use App\Service\Catalog\ProductVariantResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/produits', name: 'products_')]
class ProductsController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(ProductsRepository $productsRepository, CategoriesRepository $categoriesRepository, Request $request): Response
    {
        // Récupérer les filtres
        $categoryFilter = $request->query->get('category');
        $priceMin = $request->query->get('price_min');
        $priceMax = $request->query->get('price_max');
        $stockFilter = $request->query->get('stock');
        $brandFilter = $request->query->get('brand');
        $colorFilter = $request->query->get('color');
        $sortBy = $request->query->get('sort_by', 'name');
        $sortOrder = $request->query->get('sort_order', 'ASC');
        
        // Récupérer tous les produits ou filtrer
        // Note: une monture peut avoir plusieurs variantes (coloris/taille).
        // Le modèle est "variant-only" pour le prix/stock/couleurs.
        $query = $productsRepository->createQueryBuilder('p');
        $categoriesJoined = false;

        // Prix: on considère le prix minimum des variantes (fallback à 0 si aucune variante/prix).
        $minVariantPriceExpr = 'COALESCE((SELECT MIN(vpMin.price) FROM App\\Entity\\ProductVariant vpMin WHERE vpMin.products = p AND vpMin.price IS NOT NULL), 0)';
        
        if ($categoryFilter) {
            if (!$categoriesJoined) {
                $query->leftJoin('p.secondaryCategories', 'sc');
                $query->distinct();
                $categoriesJoined = true;
            }

            $query->andWhere('(p.categories = :category OR sc.id = :category)')
                ->setParameter('category', $categoryFilter);
        }
        
        if ($priceMin) {
            $query->andWhere($minVariantPriceExpr . ' >= :priceMin')
                ->setParameter('priceMin', (int) round(((float) $priceMin) * 100));
        }
        
        if ($priceMax) {
            $query->andWhere($minVariantPriceExpr . ' <= :priceMax')
                ->setParameter('priceMax', (int) round(((float) $priceMax) * 100));
        }
        
        if ($brandFilter) {
            $query->andWhere('LOWER(p.brand) LIKE :brand')
                  ->setParameter('brand', '%'. strtolower($brandFilter) .'%');
        }

        if ($colorFilter) {
            $query->andWhere('EXISTS (SELECT 1 FROM App\\Entity\\ProductVariant vc WHERE vc.products = p AND vc.color IS NOT NULL AND LOWER(vc.color) LIKE :color)')
                ->setParameter('color', '%'. strtolower($colorFilter) .'%');
        }

        // Filtre Stock
        if ($stockFilter === 'available') {
            $query->andWhere('EXISTS (SELECT 1 FROM App\\Entity\\ProductVariant vs WHERE vs.products = p AND COALESCE(vs.stock, 0) > 0)');
        } elseif ($stockFilter === 'unavailable') {
            $query->andWhere('NOT EXISTS (SELECT 1 FROM App\\Entity\\ProductVariant vs WHERE vs.products = p AND COALESCE(vs.stock, 0) > 0)');
        }
        
        // Tri
        if ($sortBy === 'price') {
            $query->addSelect($minVariantPriceExpr . ' AS HIDDEN effectivePrice');
            $query->orderBy('effectivePrice', $sortOrder);
        } elseif ($sortBy === 'name') {
            $query->orderBy('p.name', $sortOrder);
        } else {
            $query->orderBy('p.created_at', 'DESC');
        }
        
        $products = $query->getQuery()->getResult();
        $categories = $categoriesRepository->findAll();

        $brands = array_map(
            'current',
            $productsRepository->createQueryBuilder('p')
                ->select('DISTINCT p.brand')
                ->where('p.brand IS NOT NULL AND p.brand != \'\'')
                ->orderBy('p.brand', 'ASC')
                ->getQuery()
                ->getScalarResult()
        );

        $variantColors = array_map(
            'current',
            $productsRepository->createQueryBuilder('p')
                ->select('DISTINCT v.color')
                ->leftJoin('p.variants', 'v')
                ->where('v.color IS NOT NULL AND v.color != \'\'')
                ->getQuery()
                ->getScalarResult()
        );

        $colors = array_values(array_unique(array_filter($variantColors, static function ($c): bool {
            return is_string($c) && trim($c) !== '';
        })));
        sort($colors, SORT_NATURAL | SORT_FLAG_CASE);
        
        return $this->render('products/index.html.twig', [
            'products' => $products,
            'categories' => $categories,
            'categoryFilter' => $categoryFilter,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'stockFilter' => $stockFilter,
            'brandFilter' => $brandFilter,
            'colorFilter' => $colorFilter,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
            'brands' => $brands,
            'colors' => $colors,
        ]);
    }

    #[Route('/{slug}', name: 'details')]
    public function details(Products $product, CouponsRepository $couponRepo, ProductVariantRepository $variantRepo, ProductVariantResolver $variantResolver, Request $request): Response
    {
        $selectedVariant = null;
        $variantId = (int) $request->query->get('variant', 0);

        $selectedVariant = $variantResolver->resolveSelectedVariant($product, $variantId, $variantRepo);

        $effectivePrice = $variantResolver->getUnitPriceCents($product, $selectedVariant);
        $effectiveStock = $variantResolver->getStockQuantity($product, $selectedVariant);

        // Chercher un coupon actif pour ce produit
        $activeCoupon = $couponRepo->createQueryBuilder('c')
            ->where('c.products = :product')
            ->andWhere('c.is_valid = true')
            ->andWhere('c.validity >= :now')
            ->setParameter('product', $product)
            ->setParameter('now', new \DateTime())
            ->orderBy('c.discount', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        // Calculer le prix avec réduction si un coupon existe
        $originalPrice = $effectivePrice;
        $discountedPrice = $effectivePrice;
        $discountPercent = 0;
        
        if ($activeCoupon) {
            $couponType = $activeCoupon->getCouponsTypes();
            
            if ($couponType && $couponType->getName() === 'percentage') {
                // Réduction en pourcentage
                $discountPercent = $activeCoupon->getDiscount();
                $discountedPrice = $originalPrice - ($originalPrice * $discountPercent / 100);
            } elseif ($couponType && $couponType->getName() === 'fixed') {
                // Réduction fixe
                $discountedPrice = max(0, $originalPrice - $activeCoupon->getDiscount());
                if ($originalPrice > 0) {
                    $discountPercent = (($originalPrice - $discountedPrice) / $originalPrice) * 100;
                }
            }
        }
        
        return $this->render('products/details.html.twig', [
            'product' => $product,
            'selectedVariant' => $selectedVariant,
            'effectiveStock' => $effectiveStock,
            'activeCoupon' => $activeCoupon,
            'originalPrice' => $originalPrice,
            'discountedPrice' => $discountedPrice,
            'discountPercent' => round($discountPercent),
        ]);
    }
}
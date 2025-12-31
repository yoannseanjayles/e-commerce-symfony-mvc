<?php

namespace App\Controller;

use App\Entity\Orders;
use App\Entity\OrdersDetails;
use App\Repository\ProductVariantRepository;
use App\Repository\ProductsRepository;
use App\Service\Catalog\ProductVariantResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/commandes', name: 'app_orders_')]
class OrdersController extends AbstractController
{
    private const CART_KEY_SEPARATOR = ':';

    /** @return array{productId:int, variantId:int, selectedSize:string} */
    private static function parseCartKey(int|string $key): array
    {
        if (is_int($key) || (is_string($key) && ctype_digit($key))) {
            return ['productId' => (int) $key, 'variantId' => 0, 'selectedSize' => ''];
        }

        $raw = trim((string) $key);
        if ($raw === '' || !str_contains($raw, self::CART_KEY_SEPARATOR)) {
            $pid = ctype_digit($raw) ? (int) $raw : 0;
            return ['productId' => $pid, 'variantId' => 0, 'selectedSize' => ''];
        }

        $parts = explode(self::CART_KEY_SEPARATOR, $raw);
        $p = $parts[0] ?? '0';
        $v = $parts[1] ?? '0';
        $s = $parts[2] ?? '';
        $productId = is_string($p) && ctype_digit($p) ? (int) $p : 0;
        $variantId = is_string($v) && ctype_digit($v) ? (int) $v : 0;

        $selectedSize = is_string($s) ? trim($s) : '';

        return ['productId' => $productId, 'variantId' => $variantId, 'selectedSize' => $selectedSize];
    }

    #[Route('/ajout', name: 'add', methods: ['POST'])]
    public function add(Request $request, SessionInterface $session, ProductsRepository $productsRepository, ProductVariantRepository $variantRepository, ProductVariantResolver $variantResolver, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        if (!$this->isCsrfTokenValid('orders_add', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Action refusée (CSRF).');
            return $this->redirectToRoute('main');
        }

        $panier = $session->get('panier', []);

        if($panier === []){
            $this->addFlash('message', 'Votre panier est vide');
            return $this->redirectToRoute('main');
        }

        //Le panier n'est pas vide, on crée la commande
        $order = new Orders();

        // On remplit la commande
        $order->setUsers($this->getUser());
        $order->setReference(uniqid());

        // On parcourt le panier pour créer les détails de commande
        foreach ($panier as $item => $quantity) {
            $orderDetails = new OrdersDetails();

            $parsed = self::parseCartKey($item);
            $productId = (int) ($parsed['productId'] ?? 0);
            $variantId = (int) ($parsed['variantId'] ?? 0);
            $selectedSize = (string) ($parsed['selectedSize'] ?? '');

            if ($productId <= 0) {
                continue;
            }

            $product = $productsRepository->find($productId);
            if (!$product) {
                continue;
            }

            $variant = null;
            if ($variantId > 0) {
                $candidate = $variantRepository->find($variantId);
                if ($candidate !== null && $candidate->getProducts() !== null && $candidate->getProducts()->getId() === $product->getId()) {
                    $variant = $candidate;
                }
            }

            if ($variant === null) {
                $variant = $variantResolver->resolveSelectedOrDefaultVariant($product, $variantId, $variantRepository);
            }

            $unitPriceCents = $variantResolver->getUnitPriceCents($product, $variant);

            $orderDetails->setProducts($product);
            $orderDetails->setProductVariant($variant);
            if (method_exists($orderDetails, 'setSelectedSize')) {
                $orderDetails->setSelectedSize($selectedSize !== '' ? $selectedSize : null);
            }
            $orderDetails->setPrice($unitPriceCents);
            $orderDetails->setQuantity((int) $quantity);

            $order->addOrdersDetail($orderDetails);
        }

        $em->persist($order);
        $em->flush();

        $session->remove('panier');

        $this->addFlash('message', 'Commande créée avec succès');

        return $this->redirectToRoute('main');
    }
}

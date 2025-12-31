<?php
namespace App\Controller;

use App\Entity\Orders;
use App\Entity\OrdersDetails;
use App\Entity\ProductVariant;
use App\Entity\Products;
use App\Repository\ProductVariantRepository;
use App\Repository\ProductsRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Catalog\ProductVariantResolver;
use App\Service\Checkout\CheckoutOrderCreator;
use App\Service\Checkout\OutOfStockException;
use App\Service\Payment\StripeCheckoutService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/cart', name: 'cart_')]
class CartController extends AbstractController
{
    private const CART_KEY_SEPARATOR = ':';

    /** @return array{productId:int, variantId:int, selectedSize:string} */
    private static function parseCartKey(int|string $key): array
    {
        if (is_int($key) || (is_string($key) && ctype_digit($key))) {
            return ['productId' => (int) $key, 'variantId' => 0, 'selectedSize' => ''];
        }

        $raw = (string) $key;
        if (!str_contains($raw, self::CART_KEY_SEPARATOR)) {
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

    private static function sanitizeSizeKey(?string $size): string
    {
        if (!is_string($size)) {
            return '';
        }
        $t = trim($size);
        if ($t === '') {
            return '';
        }
        // Prevent separator injection and keep the key compact.
        $t = str_replace(self::CART_KEY_SEPARATOR, '-', $t);
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        if (function_exists('mb_strlen') ? mb_strlen($t) > 50 : strlen($t) > 50) {
            $t = function_exists('mb_substr') ? mb_substr($t, 0, 50) : substr($t, 0, 50);
        }
        return $t;
    }

    /** @return list<string> */
    private static function extractSizes(?string $raw): array
    {
        if (!is_string($raw)) {
            return [];
        }

        $normalized = str_replace(['/', ';', '|', '\\'], ',', $raw);
        $parts = array_map('trim', explode(',', $normalized));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            if (!in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    private static function buildCartKey(int $productId, int $variantId, ?string $selectedSize = null): string
    {
        $productId = max(0, $productId);
        $variantId = max(0, $variantId);
        $size = self::sanitizeSizeKey($selectedSize);
        if ($size === '') {
            return $productId . self::CART_KEY_SEPARATOR . $variantId;
        }
        return $productId . self::CART_KEY_SEPARATOR . $variantId . self::CART_KEY_SEPARATOR . $size;
    }

    #[Route('/', name: 'index')]
    public function index(SessionInterface $session, ProductsRepository $productsRepository, ProductVariantRepository $variantRepository, ProductVariantResolver $variantResolver)
    {
        $panier = $session->get('panier', []);

        // On initialise des variables
        $data = [];
        $total = 0;

        foreach ($panier as $key => $quantity) {
            $parsed = self::parseCartKey($key);
            $productId = (int) ($parsed['productId'] ?? 0);
            $variantId = (int) ($parsed['variantId'] ?? 0);
            $selectedSize = (string) ($parsed['selectedSize'] ?? '');

            if ($productId <= 0) {
                unset($panier[$key]);
                continue;
            }

            $product = $productsRepository->find($productId);

            // Si le produit n'existe plus en base (fixtures rechargées, suppression, etc.), on l'ignore et on nettoie le panier
            if (!$product) {
                unset($panier[$key]);
                continue;
            }

            $variant = null;
            if ($variantId > 0) {
                $variant = $variantResolver->resolveSelectedVariant($product, $variantId, $variantRepository);
                if ($variant === null) {
                    $variantId = 0;
                }
            }

            if ($variant === null) {
                $variant = $variantResolver->resolveSelectedOrDefaultVariant($product, $variantId, $variantRepository);
            }

            $unitPrice = $variantResolver->getUnitPriceCents($product, $variant);

            $qty = (int) $quantity;
            if ($qty <= 0) {
                unset($panier[$key]);
                continue;
            }

            $data[] = [
                'product' => $product,
                'variant' => $variant,
                'variantId' => $variantId,
                'selectedSize' => $selectedSize,
                'unitPrice' => $unitPrice,
                'quantity' => $qty,
            ];

            $total += $unitPrice * $qty;
        }

        // Met à jour le panier en session si des éléments ont été retirés
        $session->set('panier', $panier);
        
        return $this->render('cart/index.html.twig', compact('data', 'total'));
    }


    #[Route('/add/{id}', name: 'add', methods: ['POST'])]
    public function add(Products $product, SessionInterface $session, Request $request, ProductVariantRepository $variantRepository, ProductVariantResolver $variantResolver)
    {
        if (!$this->isCsrfTokenValid('cart_add_' . (string) $product->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Action refusée (CSRF).');
            return $this->redirectToRoute('cart_index');
        }

        $id = (int) $product->getId();
        $variantId = (int) $request->request->get('variant', 0);

        $variant = null;

        if ($variantId > 0 && $variantResolver->resolveSelectedVariant($product, $variantId, $variantRepository) === null) {
            $variantId = 0;
        }

        if ($variantId > 0) {
            $variant = $variantResolver->resolveSelectedVariant($product, $variantId, $variantRepository);
            if ($variant === null) {
                $variantId = 0;
            }
        }
        if ($variant === null) {
            $variant = $variantResolver->resolveSelectedOrDefaultVariant($product, $variantId, $variantRepository);
        }

        $requestedSize = trim((string) $request->request->get('size', ''));
        $allowedSizes = $variant instanceof ProductVariant ? self::extractSizes($variant->getSize()) : [];
        $selectedSize = '';
        if (count($allowedSizes) === 1) {
            $selectedSize = $allowedSizes[0];
        } elseif (count($allowedSizes) > 1) {
            if ($requestedSize === '' || !in_array($requestedSize, $allowedSizes, true)) {
                $this->addFlash('warning', 'Merci de choisir une taille avant d\'ajouter au panier.');
                return $this->redirectToRoute('products_details', ['slug' => $product->getSlug(), 'variant' => $variantId > 0 ? $variantId : null]);
            }
            $selectedSize = $requestedSize;
        }

        $key = self::buildCartKey($id, $variantId, $selectedSize);

        // On récupère le panier existant
        $panier = $session->get('panier', []);

        // On ajoute le produit dans le panier s'il n'y est pas encore
        // Sinon on incrémente sa quantité
        if (empty($panier[$key])) {
            $panier[$key] = 1;
        }else{
            $panier[$key]++;
        }

        $session->set('panier', $panier);
        
        //On redirige vers la page du panier
        return $this->redirectToRoute('cart_index');
    }

    #[Route('/remove/{id}', name: 'remove', methods: ['POST'])]
    public function remove(Products $product, SessionInterface $session, Request $request, ProductVariantRepository $variantRepository, ProductVariantResolver $variantResolver)
    {
        if (!$this->isCsrfTokenValid('cart_remove_' . (string) $product->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Action refusée (CSRF).');
            return $this->redirectToRoute('cart_index');
        }

        $id = (int) $product->getId();
        $variantId = (int) $request->request->get('variant', 0);
        $selectedSize = trim((string) $request->request->get('size', ''));

        if ($variantId > 0 && $variantResolver->resolveSelectedVariant($product, $variantId, $variantRepository) === null) {
            $variantId = 0;
        }

        $key = self::buildCartKey($id, $variantId, $selectedSize);

        // On récupère le panier existant
        $panier = $session->get('panier', []);

        // On retire le produit du panier s'il n'y a qu'1 exemplaire
        // Sinon on décrémente sa quantité
        if (empty($panier[$key]) && $selectedSize !== '') {
            // Fallback for older entries (without size in the key)
            $legacyKey = self::buildCartKey($id, $variantId);
            if (!empty($panier[$legacyKey])) {
                $key = $legacyKey;
            }
        }

        if (!empty($panier[$key])) {
            if ($panier[$key] > 1) {
                $panier[$key]--;
            }else{
                unset($panier[$key]);
            }
        }

        $session->set('panier', $panier);
        
        //On redirige vers la page du panier
        return $this->redirectToRoute('cart_index');
    }

    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function delete(Products $product, SessionInterface $session, Request $request, ProductVariantRepository $variantRepository, ProductVariantResolver $variantResolver)
    {
        if (!$this->isCsrfTokenValid('cart_delete_' . (string) $product->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Action refusée (CSRF).');
            return $this->redirectToRoute('cart_index');
        }

        $id = (int) $product->getId();
        $variantId = (int) $request->request->get('variant', 0);
        $selectedSize = trim((string) $request->request->get('size', ''));

        if ($variantId > 0 && $variantResolver->resolveSelectedVariant($product, $variantId, $variantRepository) === null) {
            $variantId = 0;
        }

        $key = self::buildCartKey($id, $variantId, $selectedSize);

        // On récupère le panier existant
        $panier = $session->get('panier', []);

        if (empty($panier[$key]) && $selectedSize !== '') {
            $legacyKey = self::buildCartKey($id, $variantId);
            if (!empty($panier[$legacyKey])) {
                $key = $legacyKey;
            }
        }

        if (!empty($panier[$key])) {
            unset($panier[$key]);
        }

        $session->set('panier', $panier);
        
        //On redirige vers la page du panier
        return $this->redirectToRoute('cart_index');
    }

    #[Route('/empty', name: 'empty', methods: ['POST'])]
    public function empty(SessionInterface $session, Request $request)
    {
        if (!$this->isCsrfTokenValid('cart_empty', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Action refusée (CSRF).');
            return $this->redirectToRoute('cart_index');
        }

        $session->remove('panier');

        return $this->redirectToRoute('cart_index');
    }

    #[Route('/validate', name: 'validate', methods: ['GET', 'POST'])]
    public function validate(Request $request, SessionInterface $session, ProductsRepository $productsRepository, EntityManagerInterface $entityManager, SiteSettingsRepository $siteSettingsRepository, StripeCheckoutService $stripeCheckoutService, ProductVariantRepository $variantRepository, ProductVariantResolver $variantResolver, CheckoutOrderCreator $orderCreator)
    {
        // Vérifier que l'utilisateur est connecté
        $user = $this->getUser();
        if (!$user) {
            // Stocker l'intention de validation pour après connexion
            $session->set('pending_cart_validation', true);
            $this->addFlash('warning', 'Vous devez être connecté pour valider votre panier.');
            return $this->redirectToRoute('app_login');
        }

        // Récupérer le panier
        $panier = $session->get('panier', []);
        
        if (empty($panier)) {
            $this->addFlash('warning', 'Votre panier est vide.');
            return $this->redirectToRoute('cart_index');
        }

        $settings = $siteSettingsRepository->findSettings();
        $stripeEnabled = $settings?->isStripeEnabled() ?? false;

        if ($stripeEnabled && !$stripeCheckoutService->isConfigured()) {
            $this->addFlash('error', 'Stripe est activé, mais la configuration est incomplète (clé secrète manquante).');
            return $this->redirectToRoute('cart_index');
        }

        // GET: show validation page (cart recap) and prepare an idempotency key.
        if ($request->isMethod('GET')) {
            $data = [];
            $total = 0;
            foreach ($panier as $key => $quantity) {
                $parsed = self::parseCartKey($key);
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
                    $variant = $variantResolver->resolveSelectedVariant($product, $variantId, $variantRepository);
                    if ($variant === null) {
                        $variantId = 0;
                    }
                }

                $unitPrice = $variantResolver->getUnitPriceCents($product, $variant);

                $qty = (int) $quantity;
                if ($qty <= 0) {
                    continue;
                }

                $data[] = [
                    'product' => $product,
                    'variant' => $variant,
                    'variantId' => $variantId,
                    'selectedSize' => $selectedSize,
                    'unitPrice' => $unitPrice,
                    'quantity' => $qty,
                ];
                $total += $unitPrice * $qty;
            }

            $idempotencyKey = bin2hex(random_bytes(16));
            $session->set('cart_validate_idempotency', $idempotencyKey);
            $session->remove('cart_validate_order_id');
            $session->remove('cart_validate_stripe_url');

            return $this->render('cart/validate.html.twig', [
                'data' => $data,
                'total' => $total,
                'stripe_enabled' => $stripeEnabled,
                'idempotency_key' => $idempotencyKey,
                'address' => method_exists($user, 'getAddress') ? (string) ($user->getAddress() ?? '') : '',
                'zipcode' => method_exists($user, 'getZipcode') ? (string) ($user->getZipcode() ?? '') : '',
                'city' => method_exists($user, 'getCity') ? (string) ($user->getCity() ?? '') : '',
            ]);
        }

        // POST: create order (Stripe or not) with CSRF + idempotence.
        if (!$this->isCsrfTokenValid('cart_validate', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Formulaire expiré. Merci de réessayer.');
            return $this->redirectToRoute('cart_validate');
        }

        $postedIdempotencyKey = (string) $request->request->get('idempotency_key', '');
        $sessionIdempotencyKey = (string) $session->get('cart_validate_idempotency', '');
        if ($postedIdempotencyKey === '' || $sessionIdempotencyKey === '' || !hash_equals($sessionIdempotencyKey, $postedIdempotencyKey)) {
            $this->addFlash('error', 'Session expirée. Merci de recommencer la validation.');
            return $this->redirectToRoute('cart_validate');
        }

        $existingOrderId = (int) $session->get('cart_validate_order_id', 0);
        if ($existingOrderId > 0) {
            $existingOrder = $entityManager->getRepository(Orders::class)->find($existingOrderId);
            if ($existingOrder instanceof Orders) {
                if ($stripeEnabled) {
                    $stripeUrl = (string) $session->get('cart_validate_stripe_url', '');
                    if ($stripeUrl !== '') {
                        return $this->redirect($stripeUrl);
                    }
                    $this->addFlash('info', 'Paiement déjà initialisé pour cette commande.');
                    return $this->redirectToRoute('cart_index');
                }

                $this->addFlash('success', sprintf('Votre commande a déjà été validée. Référence: %s', (string) $existingOrder->getReference()));
                return $this->redirectToRoute('cart_index', ['validated' => '1']);
            }
        }

        if ($stripeEnabled) {
            $address = trim((string) $request->request->get('address'));
            $zipcode = trim((string) $request->request->get('zipcode'));
            $city = trim((string) $request->request->get('city'));

            if ($address === '' || $zipcode === '' || $city === '') {
                $this->addFlash('error', 'Merci de renseigner votre adresse, code postal et ville.');
                return $this->redirectToRoute('cart_validate');
            }

            // Persist address to the user profile (used later for order confirmation / metadata)
            if (method_exists($user, 'setAddress')) {
                $user->setAddress($address);
            }
            if (method_exists($user, 'setZipcode')) {
                $user->setZipcode($zipcode);
            }
            if (method_exists($user, 'setCity')) {
                $user->setCity($city);
            }
            $entityManager->flush();
        }

        $lines = [];
        foreach ($panier as $key => $quantity) {
            $parsed = self::parseCartKey($key);
            $lines[] = [
                'productId' => (int) ($parsed['productId'] ?? 0),
                'variantId' => (int) ($parsed['variantId'] ?? 0),
                'selectedSize' => (string) ($parsed['selectedSize'] ?? ''),
                'quantity' => (int) $quantity,
            ];
        }

        try {
            $order = $orderCreator->createFromCartLines($user, $lines, $stripeEnabled);
        } catch (OutOfStockException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('cart_index');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Impossible de créer la commande. Merci de réessayer.');
            return $this->redirectToRoute('cart_index');
        }

        $session->set('cart_validate_order_id', (int) ($order->getId() ?? 0));

        if ($stripeEnabled) {
            $successUrl = $this->generateUrl('stripe_success', [], UrlGeneratorInterface::ABSOLUTE_URL) . '?session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $this->generateUrl('stripe_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

            try {
                    $checkoutSession = $stripeCheckoutService->initCheckoutSessionForOrder($order, $successUrl, $cancelUrl);
            } catch (\Throwable $e) {
                $order->setPaymentStatus(Orders::PAYMENT_STATUS_FAILED);
                $entityManager->flush();
                $this->addFlash('error', 'Impossible de démarrer le paiement Stripe.');
                return $this->redirectToRoute('cart_index');
            }

            $session->set('cart_validate_stripe_url', (string) $checkoutSession->url);

            return $this->redirect((string) $checkoutSession->url);
        }

        // Message de confirmation (on ne vide pas le panier)
        $this->addFlash('success', sprintf('Votre commande a été validée avec succès ! Référence: %s', (string) $order->getReference()));

        // Retourner sur la même page avec paramètre validated pour déclencher l'appel
        return $this->redirectToRoute('cart_index', ['validated' => '1']);
    }
}
<?php

namespace App\Service\Payment;

use App\Entity\Orders;
use App\Repository\OrdersRepository;
use App\Service\Settings\SiteSecretsResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Event as StripeEvent;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeCheckoutService
{
    private ?StripeClient $client = null;
    private ?string $clientKey = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OrdersRepository $ordersRepository,
        private readonly SiteSecretsResolver $secrets,
    ) {
    }

    private function getClient(): StripeClient
    {
        $key = $this->secrets->getStripeSecretKey();
        if ($this->client === null || $this->clientKey !== $key) {
            $this->client = new StripeClient($key);
            $this->clientKey = $key;
        }

        return $this->client;
    }

    public function isConfigured(): bool
    {
        return trim($this->secrets->getStripeSecretKey()) !== '';
    }

    public function isWebhookConfigured(): bool
    {
        return trim($this->secrets->getStripeWebhookSecret()) !== '';
    }

    public function createCheckoutSessionForOrder(Orders $order, string $successUrl, string $cancelUrl): StripeCheckoutSession
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $lineItems = [];
        $itemsPairs = [];
        foreach ($order->getOrdersDetails() as $detail) {
            $product = $detail->getProducts();
            $variant = method_exists($detail, 'getProductVariant') ? $detail->getProductVariant() : null;
            $name = $product ? (string) $product->getName() : 'Produit';
            if ($variant !== null && method_exists($variant, 'getName')) {
                $variantName = trim((string) ($variant->getName() ?? ''));
                if ($variantName !== '') {
                    $name .= ' — ' . $variantName;
                }
            }
            $unitAmount = (int) $detail->getPrice();
            $productId = $product ? (string) $product->getId() : '';
            $productSlug = ($product && method_exists($product, 'getSlug')) ? (string) ($product->getSlug() ?? '') : '';
            $variantId = ($variant && method_exists($variant, 'getId')) ? (string) ($variant->getId() ?? '') : '';
            $qty = (int) $detail->getQuantity();

            if ($productId !== '') {
                $itemsPairs[] = $variantId !== '' ? ($productId . ':' . $variantId . 'x' . $qty) : ($productId . 'x' . $qty);
            }

            $lineItems[] = [
                'price_data' => [
                    'currency' => $this->secrets->getStripeCurrency(),
                    'product_data' => [
                        'name' => $name,
                        'metadata' => array_filter([
                            'product_id' => $productId,
                            'product_slug' => $productSlug,
                            'variant_id' => $variantId,
                        ], static fn (?string $v): bool => is_string($v) && $v !== ''),
                    ],
                    'unit_amount' => $unitAmount,
                ],
                'quantity' => $qty,
            ];
        }

        $customerEmail = null;
        if ($order->getUsers()) {
            $customerEmail = $order->getUsers()->getEmail();
        }

        $customerName = (string) ($order->getUserFullName() ?? '');
        $customerAddressLine = (string) ($order->getUserAddressLine() ?? '');

        $session = $this->getClient()->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'customer_email' => $customerEmail,
            'client_reference_id' => (string) $order->getReference(),
            'metadata' => [
                'order_id' => (string) ($order->getId() ?? ''),
                'order_reference' => (string) $order->getReference(),
                'order_total' => (string) ((int) ($order->getTotal() ?? 0)),
                'cart_items' => implode(',', $itemsPairs),
                'customer_name' => $customerName,
                'customer_address' => $customerAddressLine,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'order_id' => (string) ($order->getId() ?? ''),
                    'order_reference' => (string) $order->getReference(),
                    'order_total' => (string) ((int) ($order->getTotal() ?? 0)),
                    'cart_items' => implode(',', $itemsPairs),
                    'customer_name' => $customerName,
                    'customer_address' => $customerAddressLine,
                ],
            ],
        ]);

        return $session;
    }

    public function initCheckoutSessionForOrder(Orders $order, string $successUrl, string $cancelUrl): StripeCheckoutSession
    {
        $existingSessionId = (string) ($order->getStripeCheckoutSessionId() ?? '');
        if ($existingSessionId !== '') {
            return $this->retrieveCheckoutSession($existingSessionId);
        }

        $connection = $this->entityManager->getConnection();

        /** @var StripeCheckoutSession $session */
        $session = $connection->transactional(function () use ($order, $successUrl, $cancelUrl): StripeCheckoutSession {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);

            $existingSessionId = (string) ($order->getStripeCheckoutSessionId() ?? '');
            if ($existingSessionId !== '') {
                return $this->retrieveCheckoutSession($existingSessionId);
            }

            $created = $this->createCheckoutSessionForOrder($order, $successUrl, $cancelUrl);

            $order->setStripeCheckoutSessionId((string) $created->id);
            $this->entityManager->flush();

            return $created;
        });

        return $session;
    }

    public function retrieveCheckoutSession(string $sessionId): StripeCheckoutSession
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        /** @var StripeCheckoutSession $session */
        $session = $this->getClient()->checkout->sessions->retrieve($sessionId, []);

        return $session;
    }

    public function constructWebhookEvent(string $payload, ?string $signatureHeader): StripeEvent
    {
        if (!$this->isWebhookConfigured()) {
            throw new \RuntimeException('Stripe webhook secret is not configured.');
        }

        if (!$signatureHeader) {
            throw new \RuntimeException('Missing Stripe-Signature header.');
        }

        /** @var StripeEvent $event */
        $event = Webhook::constructEvent($payload, $signatureHeader, $this->secrets->getStripeWebhookSecret());

        return $event;
    }

    public function finalizeOrderIfPaid(Orders $order, ?string $sessionId = null): bool
    {
        $sessionId = $sessionId ?? $order->getStripeCheckoutSessionId();
        if (!$sessionId) {
            return false;
        }

        $session = $this->retrieveCheckoutSession($sessionId);
        if (($session->payment_status ?? null) !== 'paid') {
            return false;
        }

        $connection = $this->entityManager->getConnection();

        return (bool) $connection->transactional(function () use ($order): bool {
            $this->entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);

            if ($order->getPaymentStatus() === Orders::PAYMENT_STATUS_PAID && $order->isStockAdjusted()) {
                return true;
            }

            foreach ($order->getOrdersDetails() as $detail) {
                $product = $detail->getProducts();
                if (!$product) {
                    continue;
                }

                $variant = method_exists($detail, 'getProductVariant') ? $detail->getProductVariant() : null;
                if ($variant === null && method_exists($product, 'getVariants')) {
                    $first = $product->getVariants()->first();
                    if ($first !== false) {
                        $variant = $first;
                    }
                }

                $qty = (int) $detail->getQuantity();

                if ($variant !== null && method_exists($variant, 'getStock') && method_exists($variant, 'setStock')) {
                    $this->entityManager->lock($variant, LockMode::PESSIMISTIC_WRITE);
                    $variantStock = $variant->getStock();
                    if ($variantStock !== null) {
                        $newStock = ((int) $variantStock) - $qty;
                        if ($newStock < 0) {
                            $newStock = 0;
                        }
                        $variant->setStock($newStock);
                    }
                }
            }

            $order->setPaymentProvider(Orders::PAYMENT_PROVIDER_STRIPE);
            $order->setPaymentStatus(Orders::PAYMENT_STATUS_PAID);
            $order->setStatus(Orders::STATUS_CONFIRMED);
            $order->setStockAdjusted(true);

            $this->entityManager->flush();

            return true;
        });
    }

    public function findOrderByCheckoutSessionId(string $sessionId): ?Orders
    {
        return $this->ordersRepository->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
    }
}

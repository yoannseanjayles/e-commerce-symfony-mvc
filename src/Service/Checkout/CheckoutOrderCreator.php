<?php

namespace App\Service\Checkout;

use App\Entity\Orders;
use App\Entity\OrdersDetails;
use App\Entity\ProductVariant;
use App\Entity\Users;
use App\Repository\ProductVariantRepository;
use App\Repository\ProductsRepository;
use App\Service\Catalog\ProductVariantResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class CheckoutOrderCreator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductsRepository $productsRepository,
        private readonly ProductVariantRepository $variantRepository,
        private readonly ProductVariantResolver $variantResolver,
    ) {
    }
    public function createFromCartLines(Users $user, array $lines, bool $stripeEnabled): Orders
    {
        $connection = $this->entityManager->getConnection();

        return $connection->transactional(function () use ($user, $lines, $stripeEnabled): Orders {
            $order = new Orders();
            $order->setUsers($user);
            $order->setReference($this->generateReference());

            if ($stripeEnabled) {
                $order->setPaymentProvider(Orders::PAYMENT_PROVIDER_STRIPE);
                $order->setPaymentStatus(Orders::PAYMENT_STATUS_PENDING);
                $order->setStatus(Orders::STATUS_PENDING_PAYMENT);
            } else {
                $order->setPaymentProvider(Orders::PAYMENT_PROVIDER_MANUAL);
                $order->setPaymentStatus(Orders::PAYMENT_STATUS_PAID);
                $order->setStatus(Orders::STATUS_CONFIRMED);
            }

            $totalAmount = 0;

            foreach ($lines as $line) {
                $productId = (int) ($line['productId'] ?? 0);
                $variantId = (int) ($line['variantId'] ?? 0);
                $selectedSize = (string) ($line['selectedSize'] ?? '');
                $qty = (int) ($line['quantity'] ?? 0);

                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                $product = $this->productsRepository->find($productId);
                if (!$product) {
                    continue;
                }

                $variant = null;
                if ($variantId > 0) {
                    $variant = $this->variantResolver->resolveSelectedVariant($product, $variantId, $this->variantRepository);
                    if ($variant === null) {
                        $variantId = 0;
                    }
                }

                $unitPrice = $this->variantResolver->getUnitPriceCents($product, $variant);

                $effectiveStock = $this->variantResolver->getStockQuantity($product, $variant);
                if ($effectiveStock < $qty) {
                    $suffix = $variant !== null && method_exists($variant, 'getName') ? (' (' . (string) ($variant->getName() ?? '') . ')') : '';
                    throw new OutOfStockException(sprintf('Stock insuffisant pour "%s"%s. Disponible: %d', (string) $product->getName(), $suffix, $effectiveStock));
                }

                $orderDetail = new OrdersDetails();
                $orderDetail->setOrders($order);
                $orderDetail->setProducts($product);
                if (method_exists($orderDetail, 'setProductVariant')) {
                    $orderDetail->setProductVariant($variant);
                }
                if (method_exists($orderDetail, 'setSelectedSize')) {
                    $orderDetail->setSelectedSize($selectedSize !== '' ? $selectedSize : null);
                }
                $orderDetail->setQuantity($qty);
                $orderDetail->setPrice($unitPrice);

                $order->addOrdersDetail($orderDetail);

                $totalAmount += $unitPrice * $qty;

                if (!$stripeEnabled) {
                    $stockVariant = $variant;
                    if ($stockVariant === null && method_exists($product, 'getVariants')) {
                        $first = $product->getVariants()->first();
                        $stockVariant = $first instanceof ProductVariant ? $first : null;
                    }

                    if ($stockVariant !== null) {
                        $this->entityManager->lock($stockVariant, LockMode::PESSIMISTIC_WRITE);

                        if (method_exists($stockVariant, 'getStock') && method_exists($stockVariant, 'setStock') && $stockVariant->getStock() !== null) {
                            $available = (int) $stockVariant->getStock();
                            if ($available < $qty) {
                                throw new OutOfStockException(sprintf('Stock insuffisant pour "%s". Disponible: %d', (string) $product->getName(), $available));
                            }

                            $stockVariant->setStock(max(0, $available - $qty));
                        }
                    }
                }
            }

            $order->setTotal($totalAmount);

            if (!$stripeEnabled) {
                $order->setStockAdjusted(true);
            }

            $this->entityManager->persist($order);
            $this->entityManager->flush();

            return $order;
        });
    }

    private function generateReference(): string
    {
        $rand = bin2hex(random_bytes(4));
        $date = (new \DateTimeImmutable())->format('YmdHis');

        return 'ORD-' . $date . '-' . strtoupper($rand);
    }
}

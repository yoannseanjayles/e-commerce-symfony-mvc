<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\ProductVariant;
use App\Entity\Products;
use App\Repository\ProductVariantRepository;

final class ProductVariantResolver
{
    public function resolveSelectedVariant(Products $product, int $variantId, ProductVariantRepository $variantRepo): ?ProductVariant
    {
        if ($variantId <= 0) {
            return null;
        }

        $candidate = $variantRepo->find($variantId);
        if (!$candidate instanceof ProductVariant) {
            return null;
        }

        $candidateProduct = $candidate->getProducts();
        if ($candidateProduct === null || $candidateProduct->getId() !== $product->getId()) {
            return null;
        }

        return $candidate;
    }

    public function resolveSelectedOrDefaultVariant(Products $product, int $variantId, ProductVariantRepository $variantRepo): ?ProductVariant
    {
        $selected = $this->resolveSelectedVariant($product, $variantId, $variantRepo);
        if ($selected !== null) {
            return $selected;
        }

        $variants = $product->getVariants();
        if (method_exists($variants, 'first')) {
            $first = $variants->first();
            return $first instanceof ProductVariant ? $first : null;
        }

        foreach ($variants as $v) {
            if ($v instanceof ProductVariant) {
                return $v;
            }
        }

        return null;
    }

    public function getUnitPriceCents(Products $product, ?ProductVariant $variant): int
    {
        if ($variant !== null && $variant->getPrice() !== null) {
            return (int) $variant->getPrice();
        }

        foreach ($product->getVariants() as $v) {
            if ($v instanceof ProductVariant && $v->getPrice() !== null) {
                return (int) $v->getPrice();
            }
        }

        return 0;
    }

    public function getStockQuantity(Products $product, ?ProductVariant $variant): int
    {
        if ($variant !== null && $variant->getStock() !== null) {
            return (int) $variant->getStock();
        }

        foreach ($product->getVariants() as $v) {
            if ($v instanceof ProductVariant && $v->getStock() !== null) {
                return (int) $v->getStock();
            }
        }

        return 0;
    }

    public function isAvailable(Products $product, ?ProductVariant $variant): bool
    {
        return $this->getStockQuantity($product, $variant) > 0;
    }
}

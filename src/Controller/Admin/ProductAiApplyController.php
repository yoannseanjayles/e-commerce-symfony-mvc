<?php

namespace App\Controller\Admin;

use App\Entity\Images;
use App\Entity\ProductVariant;
use App\Entity\Products;
use App\Repository\ProductVariantRepository;
use App\Service\Ai\AiProductImageImportService;
use App\Service\Ai\OpenAiVariantSuggestService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ProductAiApplyController extends AbstractController
{
    private const MAX_AI_IMAGE_URLS = 30;
    private const MAX_AI_VARIANTS = 300;
    private const MAX_AI_VARIANT_IMAGE_JOBS = 60;

    public function __construct(
        private CsrfTokenManagerInterface $csrfTokenManager,
        private AiProductImageImportService $imageImportService,
        private EntityManagerInterface $entityManager,
        private SluggerInterface $slugger,
    ) {
    }

    #[Route('/admin/product/{id}/ai/images/add', name: 'admin_product_ai_images_add', methods: ['POST'])]
    public function addImages(Products $product, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_ai_images_add', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $items = null;
        if (isset($payload['images']) && is_array($payload['images'])) {
            $items = [];
            foreach ($payload['images'] as $img) {
                if (!is_array($img)) {
                    continue;
                }
                $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                if ($url === '') {
                    continue;
                }
                $items[] = [
                    'url' => $url,
                    'colorTag' => isset($img['colorTag']) && is_string($img['colorTag']) ? trim($img['colorTag']) : null,
                ];
            }

            // De-dup by URL
            $seen = [];
            $items = array_values(array_filter($items, static function (array $row) use (&$seen): bool {
                $u = (string) ($row['url'] ?? '');
                if ($u === '' || isset($seen[$u])) {
                    return false;
                }
                $seen[$u] = true;
                return true;
            }));
        }

        if ($items !== null) {
            if (count($items) > self::MAX_AI_IMAGE_URLS) {
                return $this->json(['error' => 'Trop d\'images sélectionnées.'], 400);
            }
            $result = $this->imageImportService->addImagesFromItems($product, $items);
        } else {
            $urls = $payload['urls'] ?? null;
            if (!is_array($urls)) {
                return $this->json(['error' => 'Invalid payload shape.'], 400);
            }

            $urls = array_values(array_filter(array_map(static fn ($u) => is_string($u) ? trim($u) : '', $urls)));
            $urls = array_values(array_unique(array_filter($urls, static fn ($u) => $u !== '')));
            if (count($urls) > self::MAX_AI_IMAGE_URLS) {
                return $this->json(['error' => 'Trop d\'images sélectionnées.'], 400);
            }

            $result = $this->imageImportService->addImagesFromUrls($product, $urls);
        }

        return $this->json($result);
    }

    #[Route('/admin/product/ai/images/stage', name: 'admin_product_ai_images_stage', methods: ['POST'])]
    public function stageImages(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_ai_images_stage', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $items = null;
        if (isset($payload['images']) && is_array($payload['images'])) {
            $items = [];
            foreach ($payload['images'] as $img) {
                if (!is_array($img)) {
                    continue;
                }
                $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                if ($url === '') {
                    continue;
                }
                $items[] = [
                    'url' => $url,
                    'colorTag' => isset($img['colorTag']) && is_string($img['colorTag']) ? trim($img['colorTag']) : null,
                ];
            }

            // De-dup by URL
            $seen = [];
            $items = array_values(array_filter($items, static function (array $row) use (&$seen): bool {
                $u = (string) ($row['url'] ?? '');
                if ($u === '' || isset($seen[$u])) {
                    return false;
                }
                $seen[$u] = true;
                return true;
            }));
        }

        if ($items !== null) {
            if (count($items) > self::MAX_AI_IMAGE_URLS) {
                return $this->json(['error' => 'Trop d\'images sélectionnées.'], 400);
            }
            $result = $this->imageImportService->downloadImagesToUploadsWithMeta($items);
        } else {
            $urls = $payload['urls'] ?? null;
            if (!is_array($urls)) {
                return $this->json(['error' => 'Invalid payload shape.'], 400);
            }

            $urls = array_values(array_filter(array_map(static fn ($u) => is_string($u) ? trim($u) : '', $urls)));
            $urls = array_values(array_unique(array_filter($urls, static fn ($u) => $u !== '')));
            if (count($urls) > self::MAX_AI_IMAGE_URLS) {
                return $this->json(['error' => 'Trop d\'images sélectionnées.'], 400);
            }

            $result = $this->imageImportService->downloadImagesToUploads($urls);
        }
        $downloaded = $result['downloaded'] ?? [];
        if (!is_array($downloaded)) {
            $downloaded = [];
        }

        $session = $request->getSession();
        $tokenValue = bin2hex(random_bytes(16));
        $session->set('gpt_staged_images:' . $tokenValue, $downloaded);

        return $this->json([
            'token' => $tokenValue,
            'downloaded' => $downloaded,
            'skipped' => $result['skipped'] ?? 0,
            'errors' => $result['errors'] ?? [],
        ]);
    }

    #[Route('/admin/product/{id}/ai/variants/create', name: 'admin_product_ai_variants_create', methods: ['POST'])]
    public function createVariants(Products $product, Request $request, ProductVariantRepository $variantRepository): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_ai_variants_create', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $variants = $payload['variants'] ?? null;
        if (!is_array($variants)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        if (count($variants) > self::MAX_AI_VARIANTS) {
            return $this->json(['error' => 'Trop de variantes proposées.'], 400);
        }

        /** @var array<string, ProductVariant> $existingBySku */
        $existingBySku = [];
        /** @var array<string, ProductVariant> $existingByBarcode */
        $existingByBarcode = [];
        /** @var array<string, ProductVariant> $existingByNameKey */
        $existingByNameKey = [];
        foreach ($product->getVariants() as $v) {
            if (!$v instanceof ProductVariant) {
                continue;
            }
            if (is_string($v->getSku()) && $v->getSku() !== '') {
                $existingBySku[strtolower($v->getSku())] = $v;
            }
            if (is_string($v->getBarcode()) && $v->getBarcode() !== '') {
                $existingByBarcode[$v->getBarcode()] = $v;
            }
            $nameKey = strtolower(trim((string) $v->getName())) . '|' . strtolower(trim((string) ($v->getColor() ?? ''))) . '|' . strtolower(trim((string) ($v->getSize() ?? '')));
            $existingByNameKey[$nameKey] = $v;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        /** @var list<array{isNew:bool, variant:ProductVariant}> $touched */
        $touched = [];

        foreach ($variants as $variantData) {
            if (!is_array($variantData)) {
                $skipped++;
                continue;
            }

            $name = isset($variantData['name']) && is_string($variantData['name']) ? trim($variantData['name']) : '';
            if ($name === '') {
                $skipped++;
                continue;
            }

            $sku = isset($variantData['sku']) && is_string($variantData['sku']) ? trim($variantData['sku']) : null;
            $barcode = isset($variantData['barcode']) && is_string($variantData['barcode']) ? trim($variantData['barcode']) : null;
            if (is_string($barcode)) {
                $barcode = preg_replace('/\D+/', '', $barcode) ?? '';
                $barcode = trim($barcode);
            }
            $color = isset($variantData['color']) && is_string($variantData['color']) ? trim($variantData['color']) : null;
            $colorCode = isset($variantData['colorCode']) && is_string($variantData['colorCode']) ? trim($variantData['colorCode']) : null;
            $size = isset($variantData['size']) && is_string($variantData['size']) ? trim($variantData['size']) : null;

            $lensWidthMmValue = $variantData['lensWidthMm'] ?? null;
            $bridgeWidthMmValue = $variantData['bridgeWidthMm'] ?? null;
            $templeLengthMmValue = $variantData['templeLengthMm'] ?? null;
            $lensHeightMmValue = $variantData['lensHeightMm'] ?? null;

            $lensWidthMm = is_numeric($lensWidthMmValue) ? (int) $lensWidthMmValue : null;
            $bridgeWidthMm = is_numeric($bridgeWidthMmValue) ? (int) $bridgeWidthMmValue : null;
            $templeLengthMm = is_numeric($templeLengthMmValue) ? (int) $templeLengthMmValue : null;
            $lensHeightMm = is_numeric($lensHeightMmValue) ? (int) $lensHeightMmValue : null;

            $nameKey = strtolower($name) . '|' . strtolower(trim((string) $color)) . '|' . strtolower(trim((string) $size));

            $variant = null;
            $skuKey = is_string($sku) && $sku !== '' ? strtolower($sku) : null;
            $barcodeKey = is_string($barcode) && $barcode !== '' ? $barcode : null;

            if ($skuKey !== null && isset($existingBySku[$skuKey])) {
                $variant = $existingBySku[$skuKey];
            } elseif ($barcodeKey !== null && isset($existingByBarcode[$barcodeKey])) {
                $variant = $existingByBarcode[$barcodeKey];
            } elseif (isset($existingByNameKey[$nameKey])) {
                $variant = $existingByNameKey[$nameKey];
            }

            // Global collision checks (across products): keep the variant, but drop conflicting identifiers.
            if (is_string($sku) && $sku !== '') {
                $globalSku = $variantRepository->findOneBy(['sku' => $sku]);
                if ($globalSku instanceof ProductVariant && ($variant === null || $globalSku->getId() !== $variant->getId())) {
                    $errors[] = 'SKU déjà existant (autre variante): ' . $sku;
                    $sku = null;
                    $skuKey = null;
                }
            }
            if (is_string($barcode) && $barcode !== '') {
                $globalBarcode = $variantRepository->findOneBy(['barcode' => $barcode]);
                if ($globalBarcode instanceof ProductVariant && ($variant === null || $globalBarcode->getId() !== $variant->getId())) {
                    $errors[] = 'Code-barres déjà existant (autre variante): ' . $barcode;
                    $barcode = null;
                    $barcodeKey = null;
                }
            }

            // Collision checks: do not assign a SKU/barcode already used by another variant
            if ($variant !== null) {
                if ($skuKey !== null && isset($existingBySku[$skuKey]) && $existingBySku[$skuKey] !== $variant) {
                    $errors[] = 'SKU déjà utilisé par une autre variante: ' . $sku;
                    $skipped++;
                    continue;
                }
                if ($barcodeKey !== null && isset($existingByBarcode[$barcodeKey]) && $existingByBarcode[$barcodeKey] !== $variant) {
                    $errors[] = 'Code-barres déjà utilisé par une autre variante: ' . $barcode;
                    $skipped++;
                    continue;
                }
            }

            $priceValue = $variantData['price'] ?? null;
            $priceCents = null;
            if (is_numeric($priceValue)) {
                $priceCents = (int) round(((float) $priceValue) * 100);
            }

            $stockValue = $variantData['stock'] ?? null;
            $stock = null;
            if (is_numeric($stockValue)) {
                $stock = (int) $stockValue;
            }

            $isNew = false;
            if ($variant === null) {
                $variant = new ProductVariant();
                $variant->setProducts($product);
                $product->addVariant($variant);
                $this->entityManager->persist($variant);
                $isNew = true;
            }

            $variant->setName($name);

            $slug = $variant->getSlug();
            if (!is_string($slug) || trim($slug) === '') {
                $variant->setSlug($this->slugger->slug($name)->lower()->toString());
            }
            $variant->setSku($sku !== '' ? $sku : null);
            $variant->setBarcode($barcode !== '' ? $barcode : null);
            $variant->setColor($color !== '' ? $color : null);
            $variant->setColorCode($colorCode !== '' ? $colorCode : null);
            $variant->setSize($size !== '' ? $size : null);
            $variant->setLensWidthMm($lensWidthMm);
            $variant->setBridgeWidthMm($bridgeWidthMm);
            $variant->setTempleLengthMm($templeLengthMm);
            $variant->setLensHeightMm($lensHeightMm);
            $variant->setPrice($priceCents);
            $variant->setStock($stock);

            $touched[] = ['isNew' => $isNew, 'variant' => $variant];
            if ($isNew) {
                $created++;
            } else {
                $updated++;
            }
        }

        if ($created > 0 || $updated > 0) {
            try {
                $this->entityManager->persist($product);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException $e) {
                return $this->json([
                    'error' => 'Create variants failed (unique constraint).',
                    'message' => 'Un SKU ou code-barres est déjà utilisé par une autre variante.',
                ], 409);
            } catch (\Throwable $e) {
                return $this->json([
                    'error' => 'Create variants failed.',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        $createdVariants = [];
        $updatedVariants = [];
        foreach ($touched as $row) {
            $v = $row['variant'];
            if (!$v instanceof ProductVariant) {
                continue;
            }
            $entry = [
                'id' => $v->getId(),
                'name' => $v->getName(),
                'sku' => $v->getSku(),
                'barcode' => $v->getBarcode(),
                'color' => $v->getColor(),
                'size' => $v->getSize(),
            ];
            if ($row['isNew']) {
                $createdVariants[] = $entry;
            } else {
                $updatedVariants[] = $entry;
            }
        }

        return $this->json([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'createdVariants' => $createdVariants,
            'updatedVariants' => $updatedVariants,
            'errors' => array_values($errors),
        ]);
    }

    #[Route('/admin/product/{id}/ai/variant-images/add', name: 'admin_product_ai_variant_images_add', methods: ['POST'])]
    public function addVariantImage(
        Products $product,
        Request $request,
        OpenAiVariantSuggestService $variantSuggestService,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_ai_variant_images_add', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $variantData = $payload['variant'] ?? null;
        if (!is_array($variantData)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        $options = $payload['options'] ?? [];
        $useWebSearch = is_array($options) ? (bool) ($options['webSearch'] ?? false) : false;
        $aggressiveness = is_array($options) ? $this->normalizeAggressiveness($options['aggressiveness'] ?? null) : 'low';
        $variantImageCountRaw = is_array($options) ? ($options['variantImageCount'] ?? 1) : 1;
        $variantImageCount = is_numeric($variantImageCountRaw) ? (int) $variantImageCountRaw : 1;
        $variantImageCount = max(1, min(10, $variantImageCount));

        $variants = $this->collectProductVariantsByKeys($product);
        $variant = $this->findVariantInProduct($variants, $variantData);
        if (!$variant instanceof ProductVariant) {
            return $this->json(['error' => 'Variante introuvable (le produit doit déjà être sauvegardé).'], 404);
        }

        $productContext = [
            'name' => $product->getName(),
            'brand' => $product->getBrand(),
            'productType' => $product->getProductType(),
        ];

        $price = $variant->getPrice();
        $variantFields = [
            'name' => $variant->getName(),
            'slug' => $variant->getSlug(),
            'sku' => $variant->getSku(),
            'barcode' => $variant->getBarcode(),
            'color' => $variant->getColor(),
            'colorCode' => $variant->getColorCode(),
            'size' => $variant->getSize(),
            'lensWidthMm' => $variant->getLensWidthMm(),
            'bridgeWidthMm' => $variant->getBridgeWidthMm(),
            'templeLengthMm' => $variant->getTempleLengthMm(),
            'lensHeightMm' => $variant->getLensHeightMm(),
            'price' => is_numeric($price) ? ((float) $price) / 100.0 : null,
            'stock' => $variant->getStock(),
        ];

        try {
            $ai = $variantSuggestService->suggest($variantFields, $productContext, [
                'webSearch' => $useWebSearch,
                'aggressiveness' => $aggressiveness,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'ok' => false,
                'error' => 'AI request failed.',
                'message' => $e->getMessage(),
            ], 500);
        }

        $images = is_array($ai['images'] ?? null) ? $ai['images'] : [];
        $sourceUrls = [];
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
            if ($url === '') {
                continue;
            }
            if (!in_array($url, $sourceUrls, true)) {
                $sourceUrls[] = $url;
            }
            if (count($sourceUrls) >= $variantImageCount) {
                break;
            }
        }

        if ($sourceUrls === []) {
            return $this->json([
                'ok' => false,
                'variant' => [
                    'name' => $variant->getName(),
                    'color' => $variant->getColor(),
                    'size' => $variant->getSize(),
                ],
                'error' => 'Aucune image trouvée pour cette variante.',
                'sources' => $ai['sources'] ?? [],
                'notes' => $ai['notes'] ?? [],
            ], 200);
        }

        // Snapshot existing images by sourceUrl (product-local only).
        $existingBySourceUrl = [];
        foreach ($product->getImages() as $img) {
            if (!$img instanceof Images) {
                continue;
            }
            $u = $img->getSourceUrl();
            if (is_string($u) && $u !== '') {
                $existingBySourceUrl[$u] = $img;
            }
        }

        $colorTag = $variant->getColor() ?? $variant->getName();
        $items = [];
        foreach ($sourceUrls as $u) {
            $items[] = ['url' => $u, 'colorTag' => $colorTag];
            if (count($items) >= self::MAX_AI_IMAGE_URLS) {
                break;
            }
        }

        $addedResult = $this->imageImportService->addImagesFromItems($product, $items);

        // Determine primary image safely:
        // 1) Prefer the first *newly added* image id (guaranteed attached to this product)
        // 2) Else reuse an image already attached to THIS product matching the URL
        $image = null;
        $primarySourceUrl = null;

        $addedIds = $addedResult['addedImageIds'] ?? [];
        if (is_array($addedIds) && count($addedIds) > 0) {
            $firstId = (int) $addedIds[0];
            if ($firstId > 0) {
                $maybe = $this->entityManager->getRepository(Images::class)->find($firstId);
                if ($maybe instanceof Images && $maybe->getProducts()?->getId() === $product->getId()) {
                    $image = $maybe;
                    $primarySourceUrl = $maybe->getSourceUrl();
                }
            }
        }

        if (!$image instanceof Images) {
            // Refresh the map after import (includes newly added images on the product instance).
            $bySourceUrl = [];
            foreach ($product->getImages() as $img) {
                if (!$img instanceof Images) {
                    continue;
                }
                $u = $img->getSourceUrl();
                if (is_string($u) && $u !== '') {
                    $bySourceUrl[$u] = $img;
                }
            }

            foreach ($sourceUrls as $u) {
                if (isset($bySourceUrl[$u])) {
                    $image = $bySourceUrl[$u];
                    $primarySourceUrl = $u;
                    break;
                }
            }
        }

        if (!$image instanceof Images) {
            return $this->json([
                'ok' => false,
                'variant' => [
                    'name' => $variant->getName(),
                    'color' => $variant->getColor(),
                    'size' => $variant->getSize(),
                ],
                'error' => 'Téléchargement/ajout image impossible.',
                'sourceUrl' => $sourceUrls[0] ?? null,
                'importErrors' => is_array($addedResult['errors'] ?? null) ? $addedResult['errors'] : [],
                'sources' => $ai['sources'] ?? [],
                'notes' => $ai['notes'] ?? [],
            ], 200);
        }

        $variant->setPrimaryImage($image);
        $this->entityManager->flush();

        $fileName = $image->getName();
        $localPath = is_string($fileName) && $fileName !== ''
            ? ('/assets/uploads/' . rawurlencode($fileName))
            : null;

        return $this->json([
            'ok' => true,
            'variant' => [
                'id' => $variant->getId(),
                'name' => $variant->getName(),
                'color' => $variant->getColor(),
                'size' => $variant->getSize(),
            ],
            'image' => [
                'id' => $image->getId(),
                'sourceUrl' => $image->getSourceUrl(),
                'localPath' => $localPath,
                'fileName' => $fileName,
            ],
            'sourceUrl' => $primarySourceUrl ?? ($image->getSourceUrl() ?? ($sourceUrls[0] ?? null)),
            'requestedCount' => $variantImageCount,
            'attachedCount' => is_array($addedResult['addedImageIds'] ?? null) ? count((array) $addedResult['addedImageIds']) : 0,
            'sources' => $ai['sources'] ?? [],
            'notes' => $ai['notes'] ?? [],
        ]);
    }

    /** @return array{bySku:array<string,ProductVariant>, byBarcode:array<string,ProductVariant>, byNameKey:array<string,ProductVariant>} */
    private function collectProductVariantsByKeys(Products $product): array
    {
        $bySku = [];
        $byBarcode = [];
        $byNameKey = [];
        foreach ($product->getVariants() as $v) {
            if (!$v instanceof ProductVariant) {
                continue;
            }
            $sku = $v->getSku();
            if (is_string($sku) && $sku !== '') {
                $bySku[strtolower($sku)] = $v;
            }
            $barcode = $v->getBarcode();
            if (is_string($barcode) && $barcode !== '') {
                $byBarcode[$barcode] = $v;
            }
            $key = strtolower(trim((string) $v->getName())) . '|' . strtolower(trim((string) ($v->getColor() ?? ''))) . '|' . strtolower(trim((string) ($v->getSize() ?? '')));
            $byNameKey[$key] = $v;
        }

        return [
            'bySku' => $bySku,
            'byBarcode' => $byBarcode,
            'byNameKey' => $byNameKey,
        ];
    }

    /** @param array{bySku:array<string,ProductVariant>, byBarcode:array<string,ProductVariant>, byNameKey:array<string,ProductVariant>} $index */
    private function findVariantInProduct(array $index, array $variantData): ?ProductVariant
    {
        $id = null;
        if (isset($variantData['id']) && is_numeric($variantData['id'])) {
            $id = (int) $variantData['id'];
        }
        if ($id !== null && $id > 0) {
            foreach (['bySku', 'byBarcode', 'byNameKey'] as $bucket) {
                foreach ($index[$bucket] as $v) {
                    if ($v instanceof ProductVariant && $v->getId() === $id) {
                        return $v;
                    }
                }
            }
        }

        $sku = isset($variantData['sku']) && is_string($variantData['sku']) ? trim($variantData['sku']) : '';
        $barcode = isset($variantData['barcode']) && is_string($variantData['barcode']) ? trim($variantData['barcode']) : '';
        $name = isset($variantData['name']) && is_string($variantData['name']) ? trim($variantData['name']) : '';
        $color = isset($variantData['color']) && is_string($variantData['color']) ? trim($variantData['color']) : '';
        $size = isset($variantData['size']) && is_string($variantData['size']) ? trim($variantData['size']) : '';

        if ($sku !== '') {
            $k = strtolower($sku);
            if (isset($index['bySku'][$k])) {
                return $index['bySku'][$k];
            }
        }
        if ($barcode !== '') {
            if (isset($index['byBarcode'][$barcode])) {
                return $index['byBarcode'][$barcode];
            }
        }
        if ($name !== '') {
            $k = strtolower($name) . '|' . strtolower($color) . '|' . strtolower($size);
            if (isset($index['byNameKey'][$k])) {
                return $index['byNameKey'][$k];
            }
        }

        return null;
    }

    private function normalizeAggressiveness(mixed $value): string
    {
        if (!is_string($value)) {
            return 'low';
        }
        $t = strtolower(trim($value));
        return match ($t) {
            'strong', 'high' => 'high',
            'medium' => 'medium',
            'light', 'low' => 'low',
            default => 'low',
        };
    }
}

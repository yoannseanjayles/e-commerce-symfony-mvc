<?php

namespace App\Service\Ai;

use App\Service\Settings\SiteSecretsResolver;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiProductSuggestService
{
    private const MAX_SUGGESTED_IMAGES = 30;
    private const MAX_IMAGES_ONLY = 30;
    private const MAX_VARIANTS_ONLY = 20;

    /**
     * Fields we allow suggestions for.
     * @var list<string>
     */
    private const ALLOWED_SUGGESTED_FIELDS = [
        'name',
        'slug',
        'brand',
        'color',
        'barcode',
        'description',
        'price',
        'stock',
        'category',
        // Opticien
        'productType',
        'gender',
        'frameShape',
        'frameMaterial',
        'frameStyle',
        'lensWidthMm',
        'bridgeWidthMm',
        'templeLengthMm',
        'lensHeightMm',
        'polarized',
        'prescriptionAvailable',
        'uvProtection',
    ];

    /** @var list<string> */
    private const BOOLEAN_SUGGESTED_FIELDS = [
        'polarized',
        'prescriptionAvailable',
    ];

    /** @var list<string> */
    private const INTEGER_SUGGESTED_FIELDS = [
        'stock',
        'lensWidthMm',
        'bridgeWidthMm',
        'templeLengthMm',
        'lensHeightMm',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private SiteSecretsResolver $secrets,
        private string $openAiModel,
        private string $brandTone,
        private string $defaultLanguage,
        private ?string $allowedDomains,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $aiLogger,
        private readonly RequestStack $requestStack,
        private readonly AiRequestGuard $guard,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

     /**
      * @param array<string, mixed> $fields
      * @param array<string, mixed> $options
      *
    * @return array{suggested: array<string, mixed>, confidence: array<string, float>, sources: list<string>, notes: list<string>, images: list<array{url:string,label:?string,color:?string}>, variants: list<array{name:string,sku:?string,barcode:?string,color:?string,colorCode:?string,size:?string,lensWidthMm:?int,bridgeWidthMm:?int,templeLengthMm:?int,price:?float,stock:?int}>}
      */
    public function suggest(array $fields, array $options): array
    {
        $aggressiveness = $this->normalizeAggressiveness($options['aggressiveness'] ?? 'medium');
        $useWebSearch = $this->parseBool($options['webSearch'] ?? false);

        $input = $this->sanitizeFields($fields);
        $categoryOptions = $this->sanitizeCategoryOptions($fields['categoryOptions'] ?? null);
        $colorOptions = $this->sanitizeColorOptions($fields['colorOptions'] ?? null);

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch, 'full');
        $user = $this->buildUserPrompt($input, $categoryOptions, $colorOptions);

        // Let the model decide output length (no max_output_tokens cap).
        $decoded = $this->requestOpenAiJsonSchema($system, $user, $this->textFormatSchema(), $useWebSearch, 0.2, null);

        $suggested = $decoded['suggested'] ?? [];
        $confidenceGlobalRaw = $decoded['confidenceGlobal'] ?? 0;
        $confidenceGlobal = is_numeric($confidenceGlobalRaw) ? (float) $confidenceGlobalRaw : 0.0;
        $uncertainFields = is_array($decoded['uncertainFields'] ?? null) ? $decoded['uncertainFields'] : [];
        $sources = $decoded['sources'] ?? [];
        $notes = $decoded['notes'] ?? [];
        $images = $decoded['images'] ?? [];
        $variants = $decoded['variants'] ?? [];

        if (!is_array($suggested) || !is_array($sources) || !is_array($notes) || !is_array($images) || !is_array($variants)) {
            throw new \RuntimeException('Invalid JSON shape from model.');
        }

        // Enforce contract: only allow specific suggested fields.
        $filteredSuggested = [];
        foreach (self::ALLOWED_SUGGESTED_FIELDS as $field) {
            $value = $suggested[$field] ?? null;
            if (in_array($field, self::BOOLEAN_SUGGESTED_FIELDS, true)) {
                if (is_bool($value)) {
                    $filteredSuggested[$field] = $value;
                } elseif (is_string($value)) {
                    $t = strtolower(trim($value));
                    $filteredSuggested[$field] = in_array($t, ['1', 'true', 'yes', 'oui'], true)
                        ? true
                        : (in_array($t, ['0', 'false', 'no', 'non'], true) ? false : null);
                } else {
                    $filteredSuggested[$field] = null;
                }
                continue;
            }

            if (in_array($field, ['price'], true)) {
                $filteredSuggested[$field] = is_numeric($value) ? (string) $value : (is_string($value) ? $this->cleanModelText($value) : null);
                continue;
            }

            if (in_array($field, self::INTEGER_SUGGESTED_FIELDS, true)) {
                $filteredSuggested[$field] = is_numeric($value) ? (string) ((int) $value) : (is_string($value) ? $this->cleanModelText($value) : null);
                continue;
            }

            $filteredSuggested[$field] = is_string($value) ? $this->cleanModelText($value) : null;
        }

        $filteredConfidence = $this->buildConfidenceFromGlobal($confidenceGlobal, $uncertainFields, self::ALLOWED_SUGGESTED_FIELDS);

        $filteredSources = [];
        foreach ($sources as $url) {
            if (is_string($url)) {
                $t = $this->cleanModelText($url);
                if ($t !== '') {
                    $filteredSources[] = $t;
                }
            }
        }
        $filteredSources = array_values(array_unique($filteredSources));

        $filteredNotes = [];
        foreach ($notes as $note) {
            if (is_string($note)) {
                $t = $this->cleanModelText($note);
                if ($t !== '') {
                    $filteredNotes[] = $t;
                }
            }
        }

        $filteredVariants = [];
        foreach ($variants as $v) {
            if (!is_array($v)) {
                continue;
            }
            $name = $v['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $price = $v['price'] ?? null;
            $stock = $v['stock'] ?? null;

            $lensWidthMm = $v['lensWidthMm'] ?? null;
            $bridgeWidthMm = $v['bridgeWidthMm'] ?? null;
            $templeLengthMm = $v['templeLengthMm'] ?? null;

            $variantColor = isset($v['color']) && is_string($v['color']) && trim($v['color']) !== '' ? trim($v['color']) : null;
            if ($variantColor === null) {
                $variantColor = $this->inferColorFromText($name, $colorOptions);
            }

            $filteredVariants[] = [
                'name' => trim($name),
                'sku' => isset($v['sku']) && is_string($v['sku']) && trim($v['sku']) !== '' ? trim($v['sku']) : null,
                'barcode' => isset($v['barcode']) && is_string($v['barcode']) && trim($v['barcode']) !== '' ? trim($v['barcode']) : null,
                'color' => $variantColor,
                'colorCode' => isset($v['colorCode']) && is_string($v['colorCode']) && trim($v['colorCode']) !== '' ? trim($v['colorCode']) : null,
                'size' => isset($v['size']) && is_string($v['size']) && trim($v['size']) !== '' ? trim($v['size']) : null,
                'lensWidthMm' => is_numeric($lensWidthMm) ? (int) $lensWidthMm : null,
                'bridgeWidthMm' => is_numeric($bridgeWidthMm) ? (int) $bridgeWidthMm : null,
                'templeLengthMm' => is_numeric($templeLengthMm) ? (int) $templeLengthMm : null,
                'price' => is_numeric($price) ? (float) $price : null,
                'stock' => is_numeric($stock) ? (int) $stock : null,
            ];

            if (count($filteredVariants) >= 20) {
                break;
            }
        }

        // If the product comes in multiple colors, keep product.color null.
        // This avoids misleading single-color values at product-level.
        $uniqueVariantColorKeys = [];
        foreach ($filteredVariants as $v) {
            $c = $v['color'] ?? null;
            if (!is_string($c) || trim($c) === '') {
                continue;
            }
            $k = $this->normalizeKey($c);
            if ($k !== '') {
                $uniqueVariantColorKeys[$k] = true;
            }
        }

        if (count($uniqueVariantColorKeys) >= 2) {
            $filteredSuggested['color'] = null;
            $filteredNotes[] = "Couleur produit laissée à null : ce modèle existe en plusieurs coloris ; renseigne la couleur au niveau des images (images[i].color).";
        }

        // Use variant colors (preferred) or color options to infer missing image colors.
        $variantColorLabels = [];
        foreach ($filteredVariants as $v) {
            $c = $v['color'] ?? null;
            if (is_string($c) && trim($c) !== '') {
                $variantColorLabels[] = $c;
            }
        }
        $variantColorLabels = array_values(array_unique($variantColorLabels));
        $candidateColorLabels = count($variantColorLabels) > 0 ? $variantColorLabels : $colorOptions;

        $normalizedVariantColorMap = [];
        foreach ($variantColorLabels as $label) {
            $k = $this->normalizeKey($label);
            if ($k !== '') {
                $normalizedVariantColorMap[$k] = $label;
            }
        }

        $filteredImages = [];
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $url = $img['url'] ?? null;
            $label = $img['label'] ?? null;
            $color = $img['color'] ?? null;

            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $url = $this->cleanModelText($url);
            if ($url === '') {
                continue;
            }

            $labelOut = is_string($label) && trim($label) !== '' ? $this->cleanModelText($label) : null;
            $colorOut = is_string($color) && trim($color) !== '' ? $this->cleanModelText($color) : null;

            if ($colorOut === null) {
                $colorOut = $this->inferColorFromText($labelOut, $candidateColorLabels)
                    ?? $this->inferColorFromText($url, $candidateColorLabels);
            }

            // Canonicalize to exact variant label when possible.
            if (is_string($colorOut) && trim($colorOut) !== '') {
                $k = $this->normalizeKey($colorOut);
                if ($k !== '' && isset($normalizedVariantColorMap[$k])) {
                    $colorOut = $normalizedVariantColorMap[$k];
                }
            } else {
                $colorOut = null;
            }

            $filteredImages[] = [
                'url' => $url,
                'label' => $labelOut,
                'color' => $colorOut,
            ];

            if (count($filteredImages) >= self::MAX_SUGGESTED_IMAGES) {
                break;
            }
        }
        // De-dup by URL
        $seen = [];
        $filteredImages = array_values(array_filter($filteredImages, static function (array $img) use (&$seen): bool {
            $u = $img['url'];
            if (isset($seen[$u])) {
                return false;
            }
            $seen[$u] = true;
            return true;
        }));

        return [
            'suggested' => $filteredSuggested,
            'confidence' => $filteredConfidence,
            'sources' => $filteredSources,
            'notes' => $filteredNotes,
            'images' => $filteredImages,
            'variants' => $filteredVariants,
        ];
    }

    /**
     * Same input as suggest(), but returns only the product fields (no images, no variants).
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $options
     *
     * @return array{suggested: array<string, mixed>, confidence: array<string, float>, sources: list<string>, notes: list<string>}
     */
    public function suggestFieldsOnly(array $fields, array $options): array
    {
        $aggressiveness = $this->normalizeAggressiveness($options['aggressiveness'] ?? 'medium');
        $useWebSearch = $this->parseBool($options['webSearch'] ?? false);

        $input = $this->sanitizeFields($fields);
        $categoryOptions = $this->sanitizeCategoryOptions($fields['categoryOptions'] ?? null);
        $colorOptions = $this->sanitizeColorOptions($fields['colorOptions'] ?? null);

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch, 'fields');
        $user = $this->buildUserPromptFieldsOnly($input, $categoryOptions, $colorOptions);

        $decoded = $this->requestOpenAiJsonSchema($system, $user, $this->textFormatSchemaFieldsOnly(), $useWebSearch, 0.2, null);

        $suggested = $decoded['suggested'] ?? [];
        $confidenceGlobalRaw = $decoded['confidenceGlobal'] ?? 0;
        $confidenceGlobal = is_numeric($confidenceGlobalRaw) ? (float) $confidenceGlobalRaw : 0.0;
        $uncertainFields = is_array($decoded['uncertainFields'] ?? null) ? $decoded['uncertainFields'] : [];
        $sources = $decoded['sources'] ?? [];
        $notes = $decoded['notes'] ?? [];

        if (!is_array($suggested) || !is_array($sources) || !is_array($notes)) {
            throw new \RuntimeException('Invalid JSON shape from model.');
        }

        $filteredSuggested = [];
        foreach (self::ALLOWED_SUGGESTED_FIELDS as $field) {
            $value = $suggested[$field] ?? null;
            if (in_array($field, self::BOOLEAN_SUGGESTED_FIELDS, true)) {
                if (is_bool($value)) {
                    $filteredSuggested[$field] = $value;
                } elseif (is_string($value)) {
                    $t = strtolower(trim($value));
                    $filteredSuggested[$field] = in_array($t, ['1', 'true', 'yes', 'oui'], true)
                        ? true
                        : (in_array($t, ['0', 'false', 'no', 'non'], true) ? false : null);
                } else {
                    $filteredSuggested[$field] = null;
                }
                continue;
            }

            if (in_array($field, ['price'], true)) {
                $filteredSuggested[$field] = is_numeric($value) ? (string) $value : (is_string($value) ? $this->cleanModelText($value) : null);
                continue;
            }

            if (in_array($field, self::INTEGER_SUGGESTED_FIELDS, true)) {
                $filteredSuggested[$field] = is_numeric($value) ? (string) ((int) $value) : (is_string($value) ? $this->cleanModelText($value) : null);
                continue;
            }

            $filteredSuggested[$field] = is_string($value) ? $this->cleanModelText($value) : null;
        }

        $filteredConfidence = $this->buildConfidenceFromGlobal($confidenceGlobal, $uncertainFields, self::ALLOWED_SUGGESTED_FIELDS);

        $filteredSources = [];
        foreach ($sources as $url) {
            if (is_string($url)) {
                $t = $this->cleanModelText($url);
                if ($t !== '') {
                    $filteredSources[] = $t;
                }
            }
        }
        $filteredSources = array_values(array_unique($filteredSources));

        $filteredNotes = [];
        foreach ($notes as $note) {
            if (is_string($note)) {
                $t = $this->cleanModelText($note);
                if ($t !== '') {
                    $filteredNotes[] = $t;
                }
            }
        }

        return [
            'suggested' => $filteredSuggested,
            'confidence' => $filteredConfidence,
            'sources' => $filteredSources,
            'notes' => $filteredNotes,
        ];
    }

    /**
     * Suggest only images for a product context.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $options
     *
     * @return array{images: list<array{url:string,label:?string,color:?string}>, sources: list<string>, notes: list<string>}
     */
    public function suggestImagesOnly(array $fields, array $options): array
    {
        $aggressiveness = $this->normalizeAggressiveness($options['aggressiveness'] ?? 'medium');
        $useWebSearch = $this->parseBool($options['webSearch'] ?? false);

        $maxImages = $options['maxImages'] ?? null;
        $maxImagesInt = is_numeric($maxImages) ? (int) $maxImages : self::MAX_IMAGES_ONLY;
        $maxImagesInt = max(1, min(self::MAX_IMAGES_ONLY, $maxImagesInt));

        $input = $this->sanitizeFields($fields);
        $colorOptions = $this->sanitizeColorOptions($fields['colorOptions'] ?? null);

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch, 'images');
        $user = $this->buildUserPromptImagesOnly($input, $colorOptions, $maxImagesInt);

        $decoded = $this->requestOpenAiJsonSchema($system, $user, $this->textFormatSchemaImagesOnly(), $useWebSearch, 0.2, null);

        $images = $decoded['images'] ?? [];
        $sources = $decoded['sources'] ?? [];
        $notes = $decoded['notes'] ?? [];

        if (!is_array($images) || !is_array($sources) || !is_array($notes)) {
            throw new \RuntimeException('Invalid JSON shape from model.');
        }

        $filteredSources = [];
        foreach ($sources as $url) {
            if (is_string($url)) {
                $t = $this->cleanModelText($url);
                if ($t !== '') {
                    $filteredSources[] = $t;
                }
            }
        }
        $filteredSources = array_values(array_unique($filteredSources));

        $filteredNotes = [];
        foreach ($notes as $note) {
            if (is_string($note)) {
                $t = $this->cleanModelText($note);
                if ($t !== '') {
                    $filteredNotes[] = $t;
                }
            }
        }

        $filteredImages = [];
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $url = $img['url'] ?? null;
            $label = $img['label'] ?? null;
            $color = $img['color'] ?? null;

            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $url = $this->cleanModelText($url);
            if ($url === '') {
                continue;
            }

            $labelOut = is_string($label) && trim($label) !== '' ? $this->cleanModelText($label) : null;
            $colorOut = is_string($color) && trim($color) !== '' ? $this->cleanModelText($color) : null;

            if ($colorOut === null) {
                $colorOut = $this->inferColorFromText($labelOut, $colorOptions)
                    ?? $this->inferColorFromText($url, $colorOptions);
            }
            if (!is_string($colorOut) || trim($colorOut) === '') {
                $colorOut = null;
            }

            $filteredImages[] = [
                'url' => $url,
                'label' => $labelOut,
                'color' => $colorOut,
            ];

            if (count($filteredImages) >= $maxImagesInt) {
                break;
            }
        }

        $seen = [];
        $filteredImages = array_values(array_filter($filteredImages, static function (array $img) use (&$seen): bool {
            $u = $img['url'];
            if (isset($seen[$u])) {
                return false;
            }
            $seen[$u] = true;
            return true;
        }));

        return [
            'images' => $filteredImages,
            'sources' => $filteredSources,
            'notes' => $filteredNotes,
        ];
    }

    /**
     * Suggest a SINGLE best image (direct URL) for product admin usage.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $options
     *
     * @return array{url:?string,label:?string,confidence:float,note:?string}
     */
    public function suggestSingleImageOnly(array $fields, array $options): array
    {
        $useWebSearch = $this->parseBool($options['webSearch'] ?? false);

        $input = $this->sanitizeFields($fields);

        $cacheKey = 'openai_product_single_image_' . md5(json_encode([
            'brand' => $input['brand'] ?? null,
            'model' => $input['name'] ?? null,
            'color' => $input['color'] ?? null,
            'type' => $input['productType'] ?? null,
            'size' => $input['lensWidthMm'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            $hit = $cacheItem->get();
            if (is_array($hit)) {
                return $hit;
            }
        }

        $system = $this->buildSystemPromptImageOnlySingle($useWebSearch);
        $user = $this->buildUserPromptImageOnlySingle($input);

        $decoded = $this->requestOpenAiJsonSchema(
            $system,
            $user,
            $this->textFormatSchemaImageOnlySingle(),
            $useWebSearch,
            0.2,
            null,
        );

        $url = isset($decoded['url']) && is_string($decoded['url']) ? trim($decoded['url']) : '';
        $label = isset($decoded['label']) && is_string($decoded['label']) ? $this->cleanModelText($decoded['label']) : null;
        $confidenceRaw = $decoded['confidence'] ?? 0;
        $confidence = is_numeric($confidenceRaw) ? (float) $confidenceRaw : 0.0;
        $confidence = max(0.0, min(1.0, $confidence));

        $note = isset($decoded['note']) && is_string($decoded['note']) ? $this->cleanModelText($decoded['note']) : null;

        $acceptable = $url !== '' && $this->isAcceptableImageUrl($url);
        if (!$acceptable && $useWebSearch) {
            $retryUser = $user
                . "\n\nDernière URL proposée (invalide): " . $url
                . "\nDonne une AUTRE URL DIRECTE d'image (jpg/jpeg/png/webp). Utilise le minimum de Web Search. Si impossible: url=null.";
            $retrySystem = $this->buildSystemPromptImageOnlySingle(true);

            $retry = $this->requestOpenAiJsonSchema(
                $retrySystem,
                $retryUser,
                $this->textFormatSchemaImageOnlySingle(),
                true,
                0.2,
                null,
            );

            $url2 = isset($retry['url']) && is_string($retry['url']) ? trim($retry['url']) : '';
            if ($url2 !== '' && $this->isAcceptableImageUrl($url2)) {
                $url = $url2;
                $label = isset($retry['label']) && is_string($retry['label']) ? $this->cleanModelText($retry['label']) : $label;
                $confidenceRaw = $retry['confidence'] ?? $confidence;
                $confidence = is_numeric($confidenceRaw) ? max(0.0, min(1.0, (float) $confidenceRaw)) : $confidence;
                $note = isset($retry['note']) && is_string($retry['note']) ? $this->cleanModelText($retry['note']) : $note;
                $acceptable = true;
            }
        }

        if (!$acceptable) {
            $url = '';
            $confidence = 0.0;
            if ($note === null || $note === '') {
                $note = 'Aucune URL directe fiable trouvée.';
            }
        }

        $result = [
            'url' => $url !== '' ? $url : null,
            'label' => is_string($label) && $label !== '' ? $label : null,
            'confidence' => $confidence,
            'note' => is_string($note) && $note !== '' ? $note : null,
        ];

        $cacheItem->set($result);
        $cacheItem->expiresAfter(60 * 60 * 24 * 30);
        $this->cache->save($cacheItem);

        return $result;
    }

    /**
     * Suggest only variants for a product context.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $options
     *
     * @return array{variants: list<array{name:string,sku:?string,barcode:?string,color:?string,colorCode:?string,size:?string,lensWidthMm:?int,bridgeWidthMm:?int,templeLengthMm:?int,price:?float,stock:?int}>, sources: list<string>, notes: list<string>}
     */
    public function suggestVariantsOnly(array $fields, array $options): array
    {
        $aggressiveness = $this->normalizeAggressiveness($options['aggressiveness'] ?? 'medium');
        $useWebSearch = $this->parseBool($options['webSearch'] ?? false);

        $input = $this->sanitizeFields($fields);
        $colorOptions = $this->sanitizeColorOptions($fields['colorOptions'] ?? null);

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch, 'variants');
        $user = $this->buildUserPromptVariantsOnly($input, $colorOptions);

        $decoded = $this->requestOpenAiJsonSchema(
            $system,
            $user,
            $this->textFormatSchemaVariantsOnly(),
            $useWebSearch,
            0.3,
            null,
        );

        $variants = $decoded['variants'] ?? [];
        $sources = $decoded['sources'] ?? [];
        $notes = $decoded['notes'] ?? [];

        if (!is_array($variants) || !is_array($sources) || !is_array($notes)) {
            throw new \RuntimeException('Invalid JSON shape from model.');
        }

        $filteredSources = [];
        foreach ($sources as $url) {
            if (is_string($url)) {
                $t = $this->cleanModelText($url);
                if ($t !== '') {
                    $filteredSources[] = $t;
                }
            }
        }
        $filteredSources = array_values(array_unique($filteredSources));

        $filteredNotes = [];
        foreach ($notes as $note) {
            if (is_string($note)) {
                $t = $this->cleanModelText($note);
                if ($t !== '') {
                    $filteredNotes[] = $t;
                }
            }
        }

        $filteredVariants = [];
        foreach ($variants as $v) {
            if (!is_array($v)) {
                continue;
            }
            $name = $v['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            $price = $v['price'] ?? null;
            $stock = $v['stock'] ?? null;

            $lensWidthMm = $v['lensWidthMm'] ?? null;
            $bridgeWidthMm = $v['bridgeWidthMm'] ?? null;
            $templeLengthMm = $v['templeLengthMm'] ?? null;

            $variantColor = isset($v['color']) && is_string($v['color']) && trim($v['color']) !== '' ? trim($v['color']) : null;
            if ($variantColor === null) {
                $variantColor = $this->inferColorFromText((string) $name, $colorOptions);
            }

            $filteredVariants[] = [
                'name' => trim($name),
                'sku' => isset($v['sku']) && is_string($v['sku']) && trim($v['sku']) !== '' ? trim($v['sku']) : null,
                'barcode' => isset($v['barcode']) && is_string($v['barcode']) && trim($v['barcode']) !== '' ? trim($v['barcode']) : null,
                'color' => $variantColor,
                'colorCode' => isset($v['colorCode']) && is_string($v['colorCode']) && trim($v['colorCode']) !== '' ? trim($v['colorCode']) : null,
                'size' => isset($v['size']) && is_string($v['size']) && trim($v['size']) !== '' ? trim($v['size']) : null,
                'lensWidthMm' => is_numeric($lensWidthMm) ? (int) $lensWidthMm : null,
                'bridgeWidthMm' => is_numeric($bridgeWidthMm) ? (int) $bridgeWidthMm : null,
                'templeLengthMm' => is_numeric($templeLengthMm) ? (int) $templeLengthMm : null,
                'price' => is_numeric($price) ? (float) $price : null,
                'stock' => is_numeric($stock) ? (int) $stock : null,
            ];

            if (count($filteredVariants) >= self::MAX_VARIANTS_ONLY) {
                break;
            }
        }

        return [
            'variants' => $filteredVariants,
            'sources' => $filteredSources,
            'notes' => $filteredNotes,
        ];
    }

    /** @param array<string, mixed> $schemaFormat */
    private function requestOpenAiJsonSchema(
        string $system,
        string $user,
        array $schemaFormat,
        bool $useWebSearch,
        ?float $temperature = null,
        ?int $maxOutputTokens = null,
    ): array
    {
        $apiKey = $this->secrets->getOpenAiApiKey();
        if (trim($apiKey) === '') {
            throw new \RuntimeException(
                'OPENAI_API_KEY manquante (clé vide). ' .
                'Sources vérifiées: SiteSettings.openAiApiKeyOverride (si renseignée) puis variables d’environnement (OPENAI_API_KEY). ' .
                'Vérifie aussi que la migration SiteSettings a bien été appliquée en prod et que la variable est bien injectée au runtime PHP.'
            );
        }

        $start = microtime(true);

        $requestBody = [
            'model' => $this->openAiModel,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $system],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $user],
                    ],
                ],
            ],
            'text' => [
                'format' => $schemaFormat,
            ],
        ];

        if (is_numeric($temperature)) {
            $t = (float) $temperature;
            $requestBody['temperature'] = max(0.0, min(1.0, $t));
        }
        if (is_numeric($maxOutputTokens)) {
            $m = (int) $maxOutputTokens;
            if ($m > 0) {
                $requestBody['max_output_tokens'] = $m;
            }
        }

        if ($useWebSearch) {
            $requestBody['tools'] = [
                ['type' => 'web_search'],
            ];
            $requestBody['tool_choice'] = 'auto';
        }

        $request = $this->requestStack->getCurrentRequest();
        $route = $request?->attributes->get('_route');
        $route = is_string($route) ? $route : null;
        $path = $request?->getPathInfo();
        $method = $request?->getMethod();

        $schemaName = isset($schemaFormat['name']) && is_string($schemaFormat['name']) ? $schemaFormat['name'] : null;
        $action = $schemaName ?? 'openai.product_suggest';

        $cacheEnabled = $this->secrets->getAiCacheEnabled();
        $cacheTtlSeconds = $this->secrets->getAiCacheTtlSeconds();
        $cachedItem = null;
        if ($cacheEnabled) {
            $cacheKey = 'openai_product_json_schema_' . md5(json_encode([
                'model' => $this->openAiModel,
                'schema' => $schemaFormat,
                'webSearch' => $useWebSearch,
                'temperature' => is_numeric($temperature) ? (float) $temperature : null,
                'max_output_tokens' => is_numeric($maxOutputTokens) ? (int) $maxOutputTokens : null,
                'system' => $system,
                'user' => $user,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            $cachedItem = $this->cache->getItem($cacheKey);
            if ($cachedItem->isHit()) {
                $this->aiLogger->info('openai.request.cache_hit', [
                    'action' => $action,
                    'schema' => $schemaName,
                    'model' => $this->openAiModel,
                    'web_search' => $useWebSearch,
                    'route' => $route,
                    'path' => $path,
                    'method' => $method,
                ]);

                $hit = $cachedItem->get();
                if (is_array($hit)) {
                    return $hit;
                }
            }
        }

        $this->guard->enforce($action, $useWebSearch);

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->aiLogger->error('openai.request.exception', [
                'action' => $action,
                'schema' => $schemaName,
                'model' => $this->openAiModel,
                'web_search' => $useWebSearch,
                'route' => $route,
                'path' => $path,
                'method' => $method,
                'duration_ms' => $durationMs,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $raw = $response->getContent(false);
            $message = null;

            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $err = $decoded['error'] ?? null;
                    if (is_array($err) && isset($err['message']) && is_string($err['message'])) {
                        $message = trim($err['message']);
                    }
                }
            }

            if ($message === null || $message === '') {
                $message = 'OpenAI API error (HTTP ' . $status . ').';
            } else {
                $message = 'OpenAI API error (HTTP ' . $status . '): ' . $message;
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->aiLogger->warning('openai.request.http_error', [
                'action' => $action,
                'schema' => $schemaName,
                'model' => $this->openAiModel,
                'web_search' => $useWebSearch,
                'route' => $route,
                'path' => $path,
                'method' => $method,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'error' => $message,
            ]);

            throw new \RuntimeException($message);
        }

        $data = $response->toArray(false);
        $json = $this->extractJsonText($data);

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : null;
        $inputTokens = is_array($usage) && isset($usage['input_tokens']) && is_numeric($usage['input_tokens']) ? (int) $usage['input_tokens'] : null;
        $outputTokens = is_array($usage) && isset($usage['output_tokens']) && is_numeric($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;
        $totalTokens = is_array($usage) && isset($usage['total_tokens']) && is_numeric($usage['total_tokens']) ? (int) $usage['total_tokens'] : null;

        $this->aiLogger->info('openai.request.ok', [
            'action' => $action,
            'schema' => $schemaName,
            'model' => $this->openAiModel,
            'web_search' => $useWebSearch,
            'route' => $route,
            'path' => $path,
            'method' => $method,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
        ]);

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON from model.');
        }

        if ($cacheEnabled && $cachedItem !== null) {
            $cachedItem->set($decoded);
            $cachedItem->expiresAfter($cacheTtlSeconds);
            $this->cache->save($cachedItem);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $fields */
    private function sanitizeFields(array $fields): array
    {
        $out = [];

        foreach ($fields as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_string($value)) {
                $out[$key] = trim($value);
            } elseif (is_int($value) || is_float($value)) {
                $out[$key] = $value;
            } elseif (is_bool($value)) {
                $out[$key] = $value;
            } elseif ($value === null) {
                $out[$key] = null;
            }
        }

        // Only pass through what we care about (avoid accidental PII / unrelated fields)
        return [
            'name' => is_string($out['name'] ?? null) ? (string) $out['name'] : null,
            'slug' => is_string($out['slug'] ?? null) ? (string) $out['slug'] : null,
            'brand' => is_string($out['brand'] ?? null) ? (string) $out['brand'] : null,
            'color' => is_string($out['color'] ?? null) ? (string) $out['color'] : null,
            'barcode' => is_string($out['barcode'] ?? null) ? (string) $out['barcode'] : null,
            'description' => is_string($out['description'] ?? null) ? (string) $out['description'] : null,
            'price' => (is_int($out['price'] ?? null) || is_float($out['price'] ?? null)) ? (float) $out['price'] : (is_string($out['price'] ?? null) ? (string) $out['price'] : null),
            'stock' => is_int($out['stock'] ?? null) ? (int) $out['stock'] : (is_string($out['stock'] ?? null) ? (string) $out['stock'] : null),
            'category' => is_string($out['category'] ?? null) ? (string) $out['category'] : null,
            // Opticien
            'productType' => is_string($out['productType'] ?? null) ? (string) $out['productType'] : null,
            'gender' => is_string($out['gender'] ?? null) ? (string) $out['gender'] : null,
            'frameShape' => is_string($out['frameShape'] ?? null) ? (string) $out['frameShape'] : null,
            'frameMaterial' => is_string($out['frameMaterial'] ?? null) ? (string) $out['frameMaterial'] : null,
            'frameStyle' => is_string($out['frameStyle'] ?? null) ? (string) $out['frameStyle'] : null,
            'lensWidthMm' => (is_int($out['lensWidthMm'] ?? null) || is_float($out['lensWidthMm'] ?? null)) ? (int) $out['lensWidthMm'] : (is_string($out['lensWidthMm'] ?? null) && trim((string) $out['lensWidthMm']) !== '' ? (int) $out['lensWidthMm'] : null),
            'bridgeWidthMm' => (is_int($out['bridgeWidthMm'] ?? null) || is_float($out['bridgeWidthMm'] ?? null)) ? (int) $out['bridgeWidthMm'] : (is_string($out['bridgeWidthMm'] ?? null) && trim((string) $out['bridgeWidthMm']) !== '' ? (int) $out['bridgeWidthMm'] : null),
            'templeLengthMm' => (is_int($out['templeLengthMm'] ?? null) || is_float($out['templeLengthMm'] ?? null)) ? (int) $out['templeLengthMm'] : (is_string($out['templeLengthMm'] ?? null) && trim((string) $out['templeLengthMm']) !== '' ? (int) $out['templeLengthMm'] : null),
            'lensHeightMm' => (is_int($out['lensHeightMm'] ?? null) || is_float($out['lensHeightMm'] ?? null)) ? (int) $out['lensHeightMm'] : (is_string($out['lensHeightMm'] ?? null) && trim((string) $out['lensHeightMm']) !== '' ? (int) $out['lensHeightMm'] : null),
            'polarized' => is_bool($out['polarized'] ?? null) ? (bool) $out['polarized'] : (is_string($out['polarized'] ?? null) ? in_array(strtolower(trim((string) $out['polarized'])), ['1', 'true', 'yes', 'oui'], true) : null),
            'prescriptionAvailable' => is_bool($out['prescriptionAvailable'] ?? null) ? (bool) $out['prescriptionAvailable'] : (is_string($out['prescriptionAvailable'] ?? null) ? in_array(strtolower(trim((string) $out['prescriptionAvailable'])), ['1', 'true', 'yes', 'oui'], true) : null),
            'uvProtection' => is_string($out['uvProtection'] ?? null) ? (string) $out['uvProtection'] : null,
        ];
    }

    /** @return list<string> */
    private function sanitizeCategoryOptions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t === '') {
                continue;
            }
            $out[] = $t;
            if (count($out) >= 50) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    private function normalizeAggressiveness(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'low', 'prudent' => 'light',
            'light', 'leger', 'léger' => 'light',
            'high', 'agressif', 'aggressive' => 'strong',
            'strong', 'fort' => 'strong',
            default => 'medium',
        };
    }

    private function buildSystemPrompt(string $aggressiveness, bool $useWebSearch, string $mode): string
    {
        // Accept both public API vocabulary (low/medium/high) and internal one (light/medium/strong).
        $aggressiveness = $this->normalizeAggressiveness($aggressiveness);

        $modeTag = strtoupper($mode);
        $aggrTag = match ($aggressiveness) {
            'light' => 'LOW',
            'strong' => 'HIGH',
            default => 'MED',
        };
        $webTag = $useWebSearch ? '1' : '0';

        $base = "Assistant fiche produit lunettes ({$this->defaultLanguage}, {$this->brandTone}). "
            . "Réponds JSON strict (schéma). "
            . "Incertain->null+note courte. "
            . "Ne jamais inventer barcode/price/stock.";

        $modeSuffix = match ($mode) {
            'images' => 'MODE=IMAGES: images uniquement (URLs directes jpg/jpeg/png/webp). Ignore tout le reste. Retourne plusieurs URLs (respecte la limite demandée par le schéma).',
            'variants' => 'MODE=VARIANTS: variants uniquement. Pas d\'images. Donne AU MOINS 1 variante. Si tu ne peux pas distinguer plusieurs variantes, propose 1 variante "par défaut" (même nom que le modèle) avec les autres champs à null.',
            'fields' => 'MODE=FIELDS: champs produit uniquement. Pas d\'images/variants.',
            default => 'MODE=FULL: champs + (images/variants si utile).',
        };

        $aggrSuffix = match ($aggressiveness) {
            'light' => 'AGGR=LOW: prudent; si doute null.',
            'strong' => 'AGGR=HIGH: plus proactif sur le texte déductible; si déduction -> uncertainFields + note courte; jamais barcode/price/stock.',
            default => 'AGGR=MED: équilibre; complète si cohérent sinon null.',
        };

        $webSuffix = $useWebSearch
            ? 'WEB=1: web_search autorisé (utilise-le seulement si nécessaire, et reste concis).' 
            : 'WEB=0: web_search INTERDIT. N\'utilise PAS web_search.';

        $numSuffix = $mode !== 'images'
            ? 'Types: price=number(EUR)|null; stock=integer|null; mesures=integer(mm)|null.'
            : '';

        $domainsRaw = $this->allowedDomains ?? '';
        $domains = array_values(array_filter(array_map('trim', explode(',', $domainsRaw))));
        $domains = array_slice($domains, 0, 2);
        $sourcesSuffix = count($domains) > 0 ? ('Sources: ' . implode(', ', $domains) . '.') : '';

        $parts = [
            $base,
            "MODE={$modeTag} AGGR={$aggrTag} WEB={$webTag}",
            $modeSuffix,
            $aggrSuffix,
            $webSuffix,
            $numSuffix,
            $sourcesSuffix,
        ];
        $parts = array_values(array_filter($parts, static fn ($s) => is_string($s) && trim($s) !== ''));
        return implode("\n", $parts) . "\n";
    }

    private function buildSystemPromptImageOnlySingle(bool $useWebSearch): string
    {
        $webHint = $useWebSearch
            ? 'WEB=1: Web Search autorisé. Utilise-le UNIQUEMENT si nécessaire, et fais le minimum (1-2 recherches max si possible).'
            : "WEB=0: Web Search INTERDIT. N'utilise PAS Web Search.";
                return <<<TXT
Tu es un assistant expert pour trouver DES PHOTOS produit pour une fiche e-commerce lunettes.

Ton rôle: retrouver autant de PHOTOS DIRECTES que possible couvrant les différents coloris/variants du produit.

Objectif: retourner en JSON STRICT une liste d'objets `images` contenant pour chaque photo: `url` (URL DIRECTE d'image jpg/jpeg/png/webp), `label` (court), `color` (nom du coloris). Si tu peux, fournis plusieurs images par coloris (ex: packshot, vue portée).
$webHint

Contraintes:
- Réponds STRICTEMENT en JSON (aucun texte hors JSON).
- N'inclus que des URLs directes d'images (pas de pages HTML).
- Priorité: site officiel > revendeur reconnu > CDN > autres.
- Évite logos/icônes/vignettes/swatches et images trop petites.
- Si pour un coloris tu ne trouves pas d'image fiable, retourne `url=null` pour cet item et indique une courte `note`.

Format attendu (exemple minimal):
{
    "images": [
        {"url":"https://...jpg","label":"packshot","color":"noir"},
        {"url":"https://...jpg","label":"vue portée","color":"noir"},
        {"url":"https://...jpg","label":"packshot","color":"marron"}
    ],
    "sources": ["site-officiel.com"],
    "notes": ["si aucune image fiable: url=null pour l'item"]
}

TXT;
    }

    /** @param array<string,mixed> $fields */
    private function buildUserPromptImageOnlySingle(array $fields): string
    {
        $brand = is_string($fields['brand'] ?? null) ? $this->cleanModelText((string) $fields['brand']) : '';
        $model = is_string($fields['name'] ?? null) ? $this->cleanModelText((string) $fields['name']) : '';
        $type = is_string($fields['productType'] ?? null) ? $this->cleanModelText((string) $fields['productType']) : '';

        $color = is_string($fields['color'] ?? null) ? $this->cleanModelText((string) $fields['color']) : '';

        $size = null;
        $lensWidthMm = $fields['lensWidthMm'] ?? null;
        if (is_int($lensWidthMm) || is_float($lensWidthMm)) {
            $size = (string) ((int) $lensWidthMm);
        }

        $variant = trim($color . (is_string($size) && trim($size) !== '' ? (' ' . $size) : ''));

        $domainHint = '';
        $domainsRaw = $this->allowedDomains ?? '';
        $domains = array_values(array_filter(array_map('trim', explode(',', $domainsRaw))));
        if (count($domains) > 0 && $domains[0] !== '') {
            $domainHint = ' site:' . $domains[0];
        }

        $query = $this->normalizeQuery(trim("{$brand} {$model} {$variant} official product image{$domainHint}"));

        $payload = [
            'b' => $brand,
            'm' => $model,
            'variant' => $variant,
            'type' => $type,
            'q' => $query,
        ];

        return $this->jsonPrompt($payload);
    }

    private function normalizeQuery(string $q): string
    {
        $q = trim(preg_replace('/\s+/', ' ', $q) ?? $q);
        if ($q === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', $q) ?: [];
        $seen = [];
        $out = [];
        foreach ($tokens as $t) {
            if (!is_string($t)) {
                continue;
            }

            $raw = trim($t);
            if ($raw === '') {
                continue;
            }

            // Compare tokens in a punctuation-insensitive way (also removes internal punctuation like Ray-Ban vs Ray Ban).
            $k = strtolower($raw);
            $k = preg_replace('/[^\p{L}\p{N}]+/u', '', $k) ?? $k;
            $k = trim($k);
            if ($k === '') {
                continue;
            }

            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $raw;
        }

        return trim(implode(' ', $out));
    }

    private function stripBrandPrefixFromName(string $name, string $brand): string
    {
        $name = trim($name);
        $brand = trim($brand);
        if ($name === '' || $brand === '') {
            return $name;
        }

        // Fast path: literal prefix match.
        if (stripos($name, $brand) === 0) {
            $rest = trim(substr($name, strlen($brand)));
            $rest = preg_replace('/^[\s\-\–\—\:\|]+/u', '', $rest) ?? $rest;
            return $rest !== '' ? $rest : $name;
        }

        // Fuzzy prefix match (punctuation/space insensitive).
        $canon = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name)) ?? '';
        $canonBrand = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($brand)) ?? '';
        if ($canonBrand !== '' && str_starts_with($canon, $canonBrand)) {
            $brandParts = preg_split('/[^\p{L}\p{N}]+/u', $brand) ?: [];
            $brandParts = array_values(array_filter(array_map('trim', $brandParts), static fn ($p) => is_string($p) && $p !== ''));
            if ($brandParts !== []) {
                $pattern = '/^\s*' . implode('[\s\-\–\—\:\|]*', array_map(static fn ($p) => preg_quote($p, '/'), $brandParts)) . '\s*[\s\-\–\—\:\|]*/iu';
                $rest = preg_replace($pattern, '', $name) ?? $name;
                $rest = trim($rest);
                return $rest !== '' ? $rest : $name;
            }
        }

        return $name;
    }

    /** @param list<string> $fields */
    private function buildConfidenceFromGlobal(float $global, array $uncertainFields, array $fields): array
    {
        $g = max(0.0, min(1.0, $global));

        $uncertain = [];
        foreach ($uncertainFields as $f) {
            if (is_string($f) && trim($f) !== '') {
                $uncertain[trim($f)] = true;
            }
        }

        $out = [];
        foreach ($fields as $field) {
            $c = $g;
            if (isset($uncertain[$field])) {
                $c = min($c, 0.6);
            }
            $out[$field] = $c;
        }

        return $out;
    }

    private function textFormatSchemaImageOnlySingle(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'ImageOnlySingle',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'url' => ['type' => ['string', 'null']],
                    'label' => ['type' => ['string', 'null']],
                    'confidence' => ['type' => 'number'],
                    'note' => ['type' => ['string', 'null']],
                ],
                'required' => ['url', 'label', 'confidence', 'note'],
            ],
        ];
    }

    private function isAcceptableImageUrl(string $url): bool
    {
        $u = trim($url);
        if ($u === '') {
            return false;
        }

        if (preg_match('~\.(?:jpe?g|png|webp)(?:\?.*)?$~i', $u)) {
            return true;
        }

        // Heuristic for direct image CDN links with sizing params.
        $lower = strtolower($u);
        $looksCdn = str_contains($lower, 'cdn') || str_contains($lower, 'cloudfront') || str_contains($lower, 'images') || str_contains($lower, 'img');
        $hasSizeParam = str_contains($lower, 'w=') || str_contains($lower, 'width=') || str_contains($lower, 'h=') || str_contains($lower, 'height=') || str_contains($lower, 'resize') || str_contains($lower, 'format=');

        return $looksCdn && $hasSizeParam;
    }

    /** @param array<string, mixed> $fields */
    private function buildUserPrompt(array $fields, array $categoryOptions, array $colorOptions): string
    {
        $name = is_string($fields['name'] ?? null) ? $this->cleanModelText((string) $fields['name']) : '';
        $brand = is_string($fields['brand'] ?? null) ? $this->cleanModelText((string) $fields['brand']) : '';
        $barcode = is_string($fields['barcode'] ?? null) ? $this->cleanModelText((string) $fields['barcode']) : '';

        $measures = $this->buildMeasures($fields);

        $p = [
            'name' => $name,
            'brand' => $brand,
            'type' => is_string($fields['productType'] ?? null) ? $this->cleanModelText((string) $fields['productType']) : null,
            'gender' => is_string($fields['gender'] ?? null) ? $this->cleanModelText((string) $fields['gender']) : null,
            'shape' => is_string($fields['frameShape'] ?? null) ? $this->cleanModelText((string) $fields['frameShape']) : null,
            'material' => is_string($fields['frameMaterial'] ?? null) ? $this->cleanModelText((string) $fields['frameMaterial']) : null,
            'style' => is_string($fields['frameStyle'] ?? null) ? $this->cleanModelText((string) $fields['frameStyle']) : null,
            'measures' => $measures,
            'description' => $this->cleanUserDescriptionForModel($fields['description'] ?? null, 320),
            'barcode' => $barcode,
        ];

        $opt = [
            'category' => $this->pipeList($categoryOptions, 12),
            'color' => $this->pipeList($colorOptions, 18),
        ];

        $nameForQ = $this->stripBrandPrefixFromName($name, $brand);
        $q = trim(implode(' ', array_values(array_filter(
            [$brand, $nameForQ, $barcode],
            static fn ($s) => is_string($s) && trim($s) !== ''
        ))));
        $q = $this->normalizeQuery($q);

        return $this->jsonPrompt([
            'p' => $p,
            'opt' => $opt,
            'q' => $q,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function buildUserPromptFieldsOnly(array $fields, array $categoryOptions, array $colorOptions): string
    {
        $name = is_string($fields['name'] ?? null) ? $this->cleanModelText((string) $fields['name']) : '';
        $brand = is_string($fields['brand'] ?? null) ? $this->cleanModelText((string) $fields['brand']) : '';
        $barcode = is_string($fields['barcode'] ?? null) ? $this->cleanModelText((string) $fields['barcode']) : '';

        $measures = $this->buildMeasures($fields);

        $p = [
            'name' => $name,
            'brand' => $brand,
            'type' => is_string($fields['productType'] ?? null) ? $this->cleanModelText((string) $fields['productType']) : null,
            'gender' => is_string($fields['gender'] ?? null) ? $this->cleanModelText((string) $fields['gender']) : null,
            'shape' => is_string($fields['frameShape'] ?? null) ? $this->cleanModelText((string) $fields['frameShape']) : null,
            'material' => is_string($fields['frameMaterial'] ?? null) ? $this->cleanModelText((string) $fields['frameMaterial']) : null,
            'style' => is_string($fields['frameStyle'] ?? null) ? $this->cleanModelText((string) $fields['frameStyle']) : null,
            'measures' => $measures,
            'description' => $this->cleanUserDescriptionForModel($fields['description'] ?? null, 320),
            'barcode' => $barcode,
        ];

        $opt = [
            'category' => $this->pipeList($categoryOptions, 12),
            'color' => $this->pipeList($colorOptions, 18),
        ];

        $nameForQ = $this->stripBrandPrefixFromName($name, $brand);
        $q = trim(implode(' ', array_values(array_filter(
            [$brand, $nameForQ, $barcode],
            static fn ($s) => is_string($s) && trim($s) !== ''
        ))));
        $q = $this->normalizeQuery($q);

        return $this->jsonPrompt([
            'p' => $p,
            'opt' => $opt,
            'q' => $q,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function buildUserPromptImagesOnly(array $fields, array $colorOptions, int $maxImages): string
    {
        $name = is_string($fields['name'] ?? null) ? $this->cleanModelText((string) $fields['name']) : '';
        $brand = is_string($fields['brand'] ?? null) ? $this->cleanModelText((string) $fields['brand']) : '';
        $type = is_string($fields['productType'] ?? null) ? $this->cleanModelText((string) $fields['productType']) : null;
        $max = max(1, min(self::MAX_IMAGES_ONLY, $maxImages));

        $p = [
            'brand' => $brand,
            'name' => $name,
            'type' => $type,
        ];

        $opt = [
            'color' => $this->pipeList($colorOptions, 18),
        ];

        $nameForQ = $this->stripBrandPrefixFromName($name, $brand);
        $q = trim(implode(' ', array_values(array_filter(
            [$brand, $nameForQ],
            static fn ($s) => is_string($s) && trim($s) !== ''
        ))));
        $q = $this->normalizeQuery($q);
        if ($q !== '') {
            $q .= ' product image';
        }

        return $this->jsonPrompt([
            'p' => $p,
            'opt' => $opt,
            'q' => $q,
            'maxImages' => $max,
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function buildUserPromptVariantsOnly(array $fields, array $colorOptions): string
    {
        $name = is_string($fields['name'] ?? null) ? $this->cleanModelText((string) $fields['name']) : '';
        $brand = is_string($fields['brand'] ?? null) ? $this->cleanModelText((string) $fields['brand']) : '';

        $measures = $this->buildMeasures($fields);

        $p = [
            'brand' => $brand,
            'name' => $name,
            'type' => is_string($fields['productType'] ?? null) ? $this->cleanModelText((string) $fields['productType']) : null,
            'measures' => $measures,
            'description' => $this->cleanUserDescriptionForModel($fields['description'] ?? null, 360),
        ];

        $opt = [
            'color' => $this->pipeList($colorOptions, 24),
        ];

        $nameForQ = $this->stripBrandPrefixFromName($name, $brand);
        $q = trim(implode(' ', array_values(array_filter(
            [$brand, $nameForQ],
            static fn ($s) => is_string($s) && trim($s) !== ''
        ))));
        $q = $this->normalizeQuery($q);

        return $this->jsonPrompt([
            'p' => $p,
            'opt' => $opt,
            'q' => $q,
            'maxVariants' => self::MAX_VARIANTS_ONLY,
        ]);
    }

    private function parseBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        if (is_string($value)) {
            $t = strtolower(trim($value));
            if ($t === '' || $t === '0' || $t === 'false' || $t === 'no' || $t === 'non') {
                return false;
            }
            if ($t === '1' || $t === 'true' || $t === 'yes' || $t === 'oui') {
                return true;
            }
        }
        return (bool) $value;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $keys
     */
    private function compactModelContext(array $fields, array $keys): string
    {
        $out = [];

        foreach ($keys as $k) {
            if (!is_string($k) || $k === '') {
                continue;
            }

            $v = $fields[$k] ?? null;
            if ($v === null) {
                continue;
            }

            if (is_string($v)) {
                if ($k === 'description') {
                    $t = $this->cleanUserDescriptionForModel($v, 320);
                    if (!is_string($t) || trim($t) === '') {
                        continue;
                    }
                    $out[$k] = $t;
                    continue;
                }

                $t = $this->cleanModelText($v);
                if ($t === '') {
                    continue;
                }
                $out[$k] = $t;
                continue;
            }

            if (is_bool($v) || is_int($v) || is_float($v)) {
                $out[$k] = $v;
                continue;
            }

            // Defensive: ignore large/complex structures to keep prompt small.
        }

        $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    private function truncateText(string $text, int $maxChars): string
    {
        $t = trim($text);
        if ($t === '') {
            return '';
        }

        $len = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
        if ($len <= $maxChars) {
            return $t;
        }

        return function_exists('mb_substr') ? (mb_substr($t, 0, $maxChars) . '…') : (substr($t, 0, $maxChars) . '…');
    }

    /** @param array<int, mixed> $values */
    private function encodeListPreview(array $values, int $maxItems): string
    {
        $items = [];
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = $this->cleanModelText($v);
            if ($t === '') {
                continue;
            }
            $items[] = $t;
            if (count($items) >= $maxItems) {
                break;
            }
        }

        $json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[]';
    }

    /** @param list<string> $values */
    private function pipeList(array $values, int $maxItems): ?string
    {
        $out = [];
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = $this->cleanModelText($v);
            if ($t === '') {
                continue;
            }
            $out[] = $t;
            if (count($out) >= $maxItems) {
                break;
            }
        }

        $out = array_values(array_unique($out));
        if ($out === []) {
            return null;
        }

        $s = implode('|', $out);
        return $s !== '' ? $s : null;
    }

    /** @param array<string,mixed> $payload */
    private function jsonPrompt(array $payload): string
    {
        $filtered = $this->stripEmptyRecursive($payload);
        if (!is_array($filtered)) {
            $filtered = [];
        }
        $json = json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }

    private function stripEmptyRecursive(mixed $value): mixed
    {
        if (is_string($value)) {
            $t = trim($value);
            return $t !== '' ? $t : null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $k => $v) {
                $clean = $this->stripEmptyRecursive($v);
                if ($clean === null) {
                    continue;
                }

                if (is_array($clean) && $clean === []) {
                    continue;
                }

                $out[$k] = $clean;
            }

            // Preserve sequential arrays.
            if ($out !== [] && array_keys($out) === range(0, count($out) - 1)) {
                return array_values($out);
            }

            return $out;
        }

        return null;
    }

    /** @param array<string,mixed> $fields @return array<string,int> */
    private function buildMeasures(array $fields): array
    {
        $out = [];

        $lens = $fields['lensWidthMm'] ?? null;
        $bridge = $fields['bridgeWidthMm'] ?? null;
        $temple = $fields['templeLengthMm'] ?? null;
        $height = $fields['lensHeightMm'] ?? null;

        if (is_numeric($lens)) {
            $out['lens'] = (int) $lens;
        }
        if (is_numeric($bridge)) {
            $out['bridge'] = (int) $bridge;
        }
        if (is_numeric($temple)) {
            $out['temple'] = (int) $temple;
        }
        if (is_numeric($height)) {
            $out['height'] = (int) $height;
        }

        return $out;
    }

    private function formatMax(int $n): string
    {
        return (string) $n;
    }

    private function normalizeKey(string $value): string
    {
        $t = trim($value);
        if ($t === '') {
            return '';
        }
        $t = function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        return trim($t);
    }

    /** @param list<string> $labels */
    private function inferColorFromText(?string $text, array $labels): ?string
    {
        if (!is_string($text)) {
            return null;
        }

        $haystack = $this->normalizeKey($text);
        if ($haystack === '') {
            return null;
        }

        $fallbackGroups = [
            ['canon' => 'Noir', 'tokens' => ['noir', 'black', 'blk']],
            ['canon' => 'Blanc', 'tokens' => ['blanc', 'white']],
            ['canon' => 'Écaille', 'tokens' => ['écaille', 'ecaille', 'tortoise', 'tort', 'havana', 'havane']],
            ['canon' => 'Gris', 'tokens' => ['gris', 'gray', 'grey', 'smoke', 'fumé', 'fume']],
            ['canon' => 'Argent', 'tokens' => ['argent', 'silver', 'gunmetal', 'metal']],
            ['canon' => 'Or', 'tokens' => ['or', 'gold']],
            ['canon' => 'Bleu', 'tokens' => ['bleu', 'blue', 'navy']],
            ['canon' => 'Vert', 'tokens' => ['vert', 'green']],
            ['canon' => 'Rouge', 'tokens' => ['rouge', 'red', 'bordeaux', 'burgundy']],
            ['canon' => 'Rose', 'tokens' => ['rose', 'pink']],
            ['canon' => 'Violet', 'tokens' => ['violet', 'purple']],
            ['canon' => 'Jaune', 'tokens' => ['jaune', 'yellow']],
            ['canon' => 'Orange', 'tokens' => ['orange']],
            ['canon' => 'Marron', 'tokens' => ['marron', 'brown', 'brun']],
            ['canon' => 'Transparent', 'tokens' => ['transparent', 'clear', 'crystal', 'translucide']],
        ];

        $labelsAreEmpty = count($labels) === 0;

        // Try content within parentheses first (common: "(901 Noir)", "(902 Écaille)").
        if (preg_match('/\(([^)]{2,80})\)/u', $text, $m) === 1) {
            $inside = $this->normalizeKey((string) ($m[1] ?? ''));
            if ($inside !== '') {
                foreach ($labels as $label) {
                    $k = $this->normalizeKey($label);
                    if ($k !== '' && str_contains($inside, $k)) {
                        return $label;
                    }
                }

                foreach ($fallbackGroups as $g) {
                    foreach ($g['tokens'] as $token) {
                        if (str_contains($inside, (string) $token)) {
                            if (!$labelsAreEmpty) {
                                foreach ($labels as $label) {
                                    $lk = $this->normalizeKey($label);
                                    if ($lk !== '' && (str_contains($lk, (string) $token) || str_contains($lk, $this->normalizeKey((string) $g['canon'])))) {
                                        return $label;
                                    }
                                }
                            }
                            return (string) $g['canon'];
                        }
                    }
                }
            }
        }

        foreach ($labels as $label) {
            $k = $this->normalizeKey($label);
            if ($k !== '' && str_contains($haystack, $k)) {
                return $label;
            }
        }

        foreach ($fallbackGroups as $g) {
            foreach ($g['tokens'] as $token) {
                if (str_contains($haystack, (string) $token)) {
                    if (!$labelsAreEmpty) {
                        foreach ($labels as $label) {
                            $lk = $this->normalizeKey($label);
                            if ($lk !== '' && (str_contains($lk, (string) $token) || str_contains($lk, $this->normalizeKey((string) $g['canon'])))) {
                                return $label;
                            }
                        }
                    }
                    return (string) $g['canon'];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function sanitizeColorOptions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t === '') {
                continue;
            }
            $out[] = $t;
            if (count($out) >= 100) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    private function cleanModelText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Strip odd citation markers sometimes emitted by web_search in the dashboard.
        // Example: "... citeturn0search8turn0search6"
        $text = preg_replace('/\s*\x{E200}\x{E0C0}cite\x{E0C2}.*?\x{E0C1}/u', '', $text) ?? $text;

        // Fallback: remove any private-use characters (where those glyphs typically live).
        $text = preg_replace('/[\x{E000}-\x{F8FF}]/u', '', $text) ?? $text;

        return trim($text);
    }

    private function cleanUserDescriptionForModel(mixed $value, int $maxChars): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        $text = $raw;

        // Drop script/style blocks early.
        $text = preg_replace('~<\s*(script|style)[^>]*>.*?<\s*/\s*\1\s*>~is', ' ', $text) ?? $text;

        // Preserve basic separation before stripping tags.
        $text = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $text) ?? $text;
        $text = preg_replace('~</\s*(p|div|li|ul|ol|h[1-6])\s*>~i', "\n", $text) ?? $text;

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~https?://\S+~i', '', $text) ?? $text;
        $text = preg_replace("/\r\n?/", "\n", $text) ?? $text;

        $lines = preg_split("/\n+/", $text);
        if (!is_array($lines)) {
            $lines = [$text];
        }

        $kept = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }

            $t = trim($line);
            if ($t === '') {
                continue;
            }

            // Remove common e-commerce boilerplate that bloats tokens.
            if (preg_match('/\b(livraison|retours?|exp[ée]dition|paiement|remboursement|garantie|sav|service\s+client|conditions|politique|mentions\s+l[ée]gales|cookies|newsletter|cgv|contact|promo|r[ée]duction|soldes)\b/ui', $t) === 1) {
                continue;
            }

            $kept[] = $t;

            // Keep it short even before final truncation.
            if (count($kept) >= 8) {
                break;
            }
        }

        $text = trim(implode("\n", $kept));
        if ($text === '') {
            return null;
        }

        // Normalize whitespace for compact JSON prompts.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = $this->cleanModelText($text);
        if ($text === '') {
            return null;
        }

        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len < 30) {
            return null;
        }

        return $this->truncateText($text, max(40, $maxChars));
    }

    /** @return array<string, mixed> */
    private function textFormatSchema(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'ProductSuggestion',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'suggested' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => ['string', 'null']],
                            'slug' => ['type' => ['string', 'null']],
                            'brand' => ['type' => ['string', 'null']],
                            'color' => ['type' => ['string', 'null']],
                            'barcode' => ['type' => ['string', 'null']],
                            'description' => ['type' => ['string', 'null']],
                            'price' => ['type' => ['number', 'null']],
                            'stock' => ['type' => ['integer', 'null']],
                            'category' => ['type' => ['string', 'null']],
                            'productType' => ['type' => ['string', 'null']],
                            'gender' => ['type' => ['string', 'null']],
                            'frameShape' => ['type' => ['string', 'null']],
                            'frameMaterial' => ['type' => ['string', 'null']],
                            'frameStyle' => ['type' => ['string', 'null']],
                            'lensWidthMm' => ['type' => ['integer', 'null']],
                            'bridgeWidthMm' => ['type' => ['integer', 'null']],
                            'templeLengthMm' => ['type' => ['integer', 'null']],
                            'lensHeightMm' => ['type' => ['integer', 'null']],
                            'polarized' => ['type' => ['boolean', 'null']],
                            'prescriptionAvailable' => ['type' => ['boolean', 'null']],
                            'uvProtection' => ['type' => ['string', 'null']],
                        ],
                        'required' => [
                            'name',
                            'slug',
                            'brand',
                            'color',
                            'barcode',
                            'description',
                            'price',
                            'stock',
                            'category',
                            'productType',
                            'gender',
                            'frameShape',
                            'frameMaterial',
                            'frameStyle',
                            'lensWidthMm',
                            'bridgeWidthMm',
                            'templeLengthMm',
                            'lensHeightMm',
                            'polarized',
                            'prescriptionAvailable',
                            'uvProtection',
                        ],
                    ],
                    'confidenceGlobal' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'uncertainFields' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'sources' => [
                        'type' => 'array',
                        'maxItems' => 2,
                        'items' => ['type' => 'string', 'maxLength' => 220],
                    ],
                    'notes' => [
                        'type' => 'array',
                        'maxItems' => 3,
                        'items' => ['type' => 'string', 'maxLength' => 160],
                    ],
                    'images' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'url' => ['type' => ['string', 'null']],
                                'label' => ['type' => ['string', 'null']],
                                'color' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['url', 'label', 'color'],
                        ],
                    ],
                    'variants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'name' => ['type' => ['string', 'null']],
                                'sku' => ['type' => ['string', 'null']],
                                'barcode' => ['type' => ['string', 'null']],
                                'color' => ['type' => ['string', 'null']],
                                'colorCode' => ['type' => ['string', 'null']],
                                'size' => ['type' => ['string', 'null']],
                                'lensWidthMm' => ['type' => ['integer', 'null']],
                                'bridgeWidthMm' => ['type' => ['integer', 'null']],
                                'templeLengthMm' => ['type' => ['integer', 'null']],
                                'price' => ['type' => ['number', 'null']],
                                'stock' => ['type' => ['integer', 'null']],
                            ],
                            'required' => [
                                'name',
                                'sku',
                                'barcode',
                                'color',
                                'colorCode',
                                'size',
                                'lensWidthMm',
                                'bridgeWidthMm',
                                'templeLengthMm',
                                'price',
                                'stock',
                            ],
                        ],
                    ],
                ],
                'required' => ['suggested', 'confidenceGlobal', 'uncertainFields', 'sources', 'notes', 'images', 'variants'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function textFormatSchemaFieldsOnly(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'ProductSuggestionFieldsOnly',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'suggested' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => ['string', 'null']],
                            'slug' => ['type' => ['string', 'null']],
                            'brand' => ['type' => ['string', 'null']],
                            'color' => ['type' => ['string', 'null']],
                            'barcode' => ['type' => ['string', 'null']],
                            'description' => ['type' => ['string', 'null']],
                            'price' => ['type' => ['number', 'null']],
                            'stock' => ['type' => ['integer', 'null']],
                            'category' => ['type' => ['string', 'null']],
                            'productType' => ['type' => ['string', 'null']],
                            'gender' => ['type' => ['string', 'null']],
                            'frameShape' => ['type' => ['string', 'null']],
                            'frameMaterial' => ['type' => ['string', 'null']],
                            'frameStyle' => ['type' => ['string', 'null']],
                            'lensWidthMm' => ['type' => ['integer', 'null']],
                            'bridgeWidthMm' => ['type' => ['integer', 'null']],
                            'templeLengthMm' => ['type' => ['integer', 'null']],
                            'lensHeightMm' => ['type' => ['integer', 'null']],
                            'polarized' => ['type' => ['boolean', 'null']],
                            'prescriptionAvailable' => ['type' => ['boolean', 'null']],
                            'uvProtection' => ['type' => ['string', 'null']],
                        ],
                        'required' => [
                            'name',
                            'slug',
                            'brand',
                            'color',
                            'barcode',
                            'description',
                            'price',
                            'stock',
                            'category',
                            'productType',
                            'gender',
                            'frameShape',
                            'frameMaterial',
                            'frameStyle',
                            'lensWidthMm',
                            'bridgeWidthMm',
                            'templeLengthMm',
                            'lensHeightMm',
                            'polarized',
                            'prescriptionAvailable',
                            'uvProtection',
                        ],
                    ],
                    'confidenceGlobal' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'uncertainFields' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'sources' => [
                        'type' => 'array',
                        'maxItems' => 2,
                        'items' => ['type' => 'string', 'maxLength' => 220],
                    ],
                    'notes' => [
                        'type' => 'array',
                        'maxItems' => 3,
                        'items' => ['type' => 'string', 'maxLength' => 160],
                    ],
                ],
                'required' => ['suggested', 'confidenceGlobal', 'uncertainFields', 'sources', 'notes'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function textFormatSchemaImagesOnly(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'ProductSuggestionImagesOnly',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'sources' => [
                        'type' => 'array',
                        'maxItems' => 2,
                        'items' => ['type' => 'string', 'maxLength' => 220],
                    ],
                    'notes' => [
                        'type' => 'array',
                        'maxItems' => 3,
                        'items' => ['type' => 'string', 'maxLength' => 160],
                    ],
                    'images' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'url' => ['type' => ['string', 'null']],
                                'label' => ['type' => ['string', 'null']],
                                'color' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['url', 'label', 'color'],
                        ],
                    ],
                ],
                'required' => ['sources', 'notes', 'images'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function textFormatSchemaVariantsOnly(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'ProductSuggestionVariantsOnly',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'sources' => [
                        'type' => 'array',
                        'maxItems' => 2,
                        'items' => ['type' => 'string', 'maxLength' => 220],
                    ],
                    'notes' => [
                        'type' => 'array',
                        'maxItems' => 3,
                        'minItems' => 1,
                        'items' => ['type' => 'string', 'maxLength' => 160],
                    ],
                    'variants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'name' => ['type' => ['string', 'null']],
                                'sku' => ['type' => ['string', 'null']],
                                'barcode' => ['type' => ['string', 'null']],
                                'color' => ['type' => ['string', 'null']],
                                'colorCode' => ['type' => ['string', 'null']],
                                'size' => ['type' => ['string', 'null']],
                                'lensWidthMm' => ['type' => ['integer', 'null']],
                                'bridgeWidthMm' => ['type' => ['integer', 'null']],
                                'templeLengthMm' => ['type' => ['integer', 'null']],
                                'price' => ['type' => ['number', 'null']],
                                'stock' => ['type' => ['integer', 'null']],
                            ],
                            'required' => [
                                'name',
                                'sku',
                                'barcode',
                                'color',
                                'colorCode',
                                'size',
                                'lensWidthMm',
                                'bridgeWidthMm',
                                'templeLengthMm',
                                'price',
                                'stock',
                            ],
                        ],
                    ],
                ],
                'required' => ['sources', 'notes', 'variants'],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    private function extractJsonText(array $data): string
    {
        if (isset($data['output_text']) && is_string($data['output_text']) && trim($data['output_text']) !== '') {
            return (string) $data['output_text'];
        }

        $candidates = [];
        $refusal = null;
        $output = $data['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $content = $item['content'] ?? null;
                if (!is_array($content)) {
                    continue;
                }
                foreach ($content as $c) {
                    if (is_array($c)) {
                        if ($refusal === null && (($c['type'] ?? null) === 'refusal') && isset($c['refusal']) && is_string($c['refusal'])) {
                            $refusal = trim($c['refusal']);
                        }
                        if (isset($c['text']) && is_string($c['text'])) {
                            $candidates[] = $c['text'];
                        }
                        if (isset($c['output_text']) && is_string($c['output_text'])) {
                            $candidates[] = $c['output_text'];
                        }
                    }
                }
            }
        }

        if (is_string($refusal) && $refusal !== '') {
            throw new \RuntimeException('OpenAI refusal: ' . $refusal);
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && str_starts_with($candidate, '{')) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to extract JSON from OpenAI response.');
    }
}

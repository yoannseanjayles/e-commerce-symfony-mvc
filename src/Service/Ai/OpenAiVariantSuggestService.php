<?php

namespace App\Service\Ai;

use App\Service\Catalog\ColorCatalog;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OpenAiVariantSuggestService
{
    private const MAX_IMAGES = 30;

    public function __construct(
        private readonly OpenAiAdminJsonSchemaService $client,
        private readonly ColorCatalog $colorCatalog,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $variantFields
     * @param array<string, mixed> $productContext
     * @param array<string, mixed> $options Supported: webSearch(bool), aggressiveness(low|medium|high)
     *
     * @return array{
     *   suggested: array<string, mixed>,
     *   confidence: array<string, float>,
     *   sources: list<string>,
     *   notes: list<string>,
     *   images: list<array{url:string,label:?string}>
     * }
     */
    public function suggest(array $variantFields, array $productContext, array $options = []): array
    {
        [$useWebSearch, $aggressiveness, $maxImages] = $this->normalizeOptions($options);
        $allowedColors = $this->sanitizeAllowedColors($variantFields['colorOptions'] ?? null);

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch, 'full');
        $user = $this->buildUserPrompt($variantFields, $productContext, $allowedColors);

        $schemaFormat = $this->schemaFull($maxImages);

        $decoded = $this->client->request($system, $user, $schemaFormat, [
            'webSearch' => $useWebSearch,
            'action' => 'openai.variant_suggest_full',
            'temperature' => 0.2,
        ]);
        return $this->hydrateFull($decoded, $allowedColors, $maxImages);
    }

    /**
     * Same input as suggest(), but returns only the variant fields (no images).
     *
     * @param array<string, mixed> $variantFields
     * @param array<string, mixed> $productContext
     * @param array<string, mixed> $options
     * @return array{suggested:array<string,mixed>, confidence:array<string,float>, sources:list<string>, notes:list<string>}
     */
    public function suggestFieldsOnly(array $variantFields, array $productContext, array $options = []): array
    {
        [$useWebSearch, $aggressiveness] = $this->normalizeOptions($options);
        $allowedColors = $this->sanitizeAllowedColors($variantFields['colorOptions'] ?? null);

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch, 'fields');
        $user = $this->buildUserPrompt($variantFields, $productContext, $allowedColors);

        $decoded = $this->client->request($system, $user, $this->schemaFieldsOnly(), [
            'webSearch' => $useWebSearch,
            'action' => 'openai.variant_suggest_fields',
            'temperature' => 0.2,
        ]);
        return $this->hydrateFieldsOnly($decoded, $allowedColors);
    }

    /**
     * Same input as suggest(), but returns only images + sources/notes.
     *
     * @param array<string, mixed> $variantFields
     * @param array<string, mixed> $productContext
     * @param array<string, mixed> $options
     * @return array{images:list<array{url:string,label:?string}>, sources:list<string>, notes:list<string>}
     */
    public function suggestImagesOnly(array $variantFields, array $productContext, array $options = []): array
    {
        [$useWebSearch, $aggressiveness, $maxImages] = $this->normalizeOptions($options);
        $allowedColors = $this->sanitizeAllowedColors($variantFields['colorOptions'] ?? null);

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch, 'images');
        $user = $this->buildUserPromptImagesOnly($variantFields, $productContext, $allowedColors, $maxImages);

        $decoded = $this->client->request($system, $user, $this->schemaImagesOnly($maxImages), [
            'webSearch' => $useWebSearch,
            'action' => 'openai.variant_suggest_images',
            'temperature' => 0.2,
        ]);
        return $this->hydrateImagesOnly($decoded, $maxImages);
    }

    /**
     * Suggest a SINGLE best image (direct URL) for a variant.
     *
     * @param array<string, mixed> $variantFields
     * @param array<string, mixed> $productContext
     * @param array<string, mixed> $options
     *
     * @return array{url:?string,label:?string,confidence:float,note:?string}
     */
    public function suggestSingleImageOnly(array $variantFields, array $productContext, array $options = []): array
    {
        [$useWebSearch] = $this->normalizeOptions($options);

        $brand = $this->cleanString($productContext['brand'] ?? null);
        $model = $this->cleanString($productContext['name'] ?? null);
        $color = $this->cleanString($variantFields['color'] ?? null) ?? $this->cleanString($variantFields['colorCode'] ?? null);
        $type = $this->cleanString($productContext['productType'] ?? null);
        $size = $this->cleanString($variantFields['size'] ?? null);
        if (($size === null || trim($size) === '') && is_numeric($variantFields['lensWidthMm'] ?? null)) {
            $size = (string) ((int) $variantFields['lensWidthMm']);
        }

        $cacheKey = 'openai_variant_single_image_' . md5(json_encode([
            'brand' => $brand,
            'model' => $model,
            'color' => $color,
            'type' => $type,
            'size' => $size,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit()) {
            $hit = $cacheItem->get();
            if (is_array($hit)) {
                return $hit;
            }
        }

        $system = $this->buildSystemPromptImageOnlySingle($useWebSearch);
        $user = $this->buildUserPromptImageOnlySingleMinimal($variantFields, $productContext);

        $decoded = $this->client->request($system, $user, $this->schemaImageOnlySingle(), [
            'webSearch' => $useWebSearch,
            'action' => 'openai.variant_image_single',
            'temperature' => 0.2,
        ]);

        $url = isset($decoded['url']) && is_string($decoded['url']) ? trim($decoded['url']) : '';
        $label = isset($decoded['label']) && is_string($decoded['label']) ? $this->cleanString($decoded['label']) : null;
        $confidenceRaw = $decoded['confidence'] ?? 0;
        $confidence = is_numeric($confidenceRaw) ? (float) $confidenceRaw : 0.0;
        $confidence = max(0.0, min(1.0, $confidence));
        $note = isset($decoded['note']) && is_string($decoded['note']) ? $this->cleanString($decoded['note']) : null;

        $acceptable = $url !== '' && $this->isAcceptableImageUrl($url);
        if (!$acceptable && $useWebSearch) {
            $retrySystem = $this->buildSystemPromptImageOnlySingle(true);
            $retryUser = $user
                . "\n\nDernière URL proposée (invalide): " . $url
                . "\nDonne une AUTRE URL DIRECTE d'image (jpg/jpeg/png/webp). Utilise le minimum de Web Search. Si impossible: url=null.";

            $retry = $this->client->request($retrySystem, $retryUser, $this->schemaImageOnlySingle(), [
                'webSearch' => true,
                'action' => 'openai.variant_image_single_retry',
                'temperature' => 0.2,
            ]);

            $url2 = isset($retry['url']) && is_string($retry['url']) ? trim($retry['url']) : '';
            if ($url2 !== '' && $this->isAcceptableImageUrl($url2)) {
                $url = $url2;
                $label = isset($retry['label']) && is_string($retry['label']) ? $this->cleanString($retry['label']) : $label;
                $confidenceRaw = $retry['confidence'] ?? $confidence;
                $confidence = is_numeric($confidenceRaw) ? max(0.0, min(1.0, (float) $confidenceRaw)) : $confidence;
                $note = isset($retry['note']) && is_string($retry['note']) ? $this->cleanString($retry['note']) : $note;
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
            'label' => is_string($label) && trim($label) !== '' ? $label : null,
            'confidence' => $confidence,
            'note' => is_string($note) && trim($note) !== '' ? $note : null,
        ];

        $cacheItem->set($result);
        $cacheItem->expiresAfter(60 * 60 * 24 * 30);
        $this->cache->save($cacheItem);

        return $result;
    }

    /** @param array<string, mixed> $options @return array{0:bool,1:string,2:int} */
    private function normalizeOptions(array $options): array
    {
        $useWebSearch = $this->parseBool($options['webSearch'] ?? false);

        $aggressiveness = is_string($options['aggressiveness'] ?? null)
            ? strtolower(trim((string) $options['aggressiveness']))
            : 'low';

        // Accept product panel vocabulary for UX parity.
        if ($aggressiveness === 'light') {
            $aggressiveness = 'low';
        } elseif ($aggressiveness === 'strong') {
            $aggressiveness = 'high';
        }

        if (!in_array($aggressiveness, ['low', 'medium', 'high'], true)) {
            $aggressiveness = 'low';
        }

        $maxImagesRaw = $options['maxImages'] ?? null;
        $maxImages = is_numeric($maxImagesRaw) ? (int) $maxImagesRaw : self::MAX_IMAGES;
        $maxImages = max(1, min(self::MAX_IMAGES, $maxImages));

        return [$useWebSearch, $aggressiveness, $maxImages];
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

    private function buildSystemPrompt(string $aggressiveness, bool $useWebSearch, string $mode): string
    {
        $modeTag = strtoupper($mode);
        $aggrTag = match ($aggressiveness) {
            'high' => 'HIGH',
            'medium' => 'MED',
            default => 'LOW',
        };
        $webTag = $useWebSearch ? '1' : '0';

        $base = $mode === 'images'
            ? 'Assistant photo produit variante lunettes (FR). Réponds JSON strict (schéma).'
            : 'Assistant fiche variante lunettes (FR). Réponds JSON strict (schéma). Incertain->null+note courte. Ne jamais inventer barcode/price/stock/mesures.';

        $modeSuffix = match ($mode) {
            'images' => 'MODE=IMAGES: images uniquement (URLs directes jpg/jpeg/png/webp). Ignore tout le reste. Retourne plusieurs URLs (respecte la limite demandée par le schéma).',
            'fields' => 'MODE=FIELDS: champs variante uniquement. Pas d\'images.',
            default => 'MODE=FULL: champs + images si utile.',
        };

        $aggrSuffix = match ($aggressiveness) {
            'high' => 'AGGR=HIGH: plus proactif sur le texte si cohérent; valeurs numériques seulement si fiables, sinon null.',
            'medium' => 'AGGR=MED: équilibre; complète si cohérent sinon null.',
            default => 'AGGR=LOW: prudent; si doute null.',
        };

        $webSuffix = $useWebSearch
            ? 'WEB=1: web_search autorisé (utilise-le seulement si nécessaire, et reste concis).'
            : 'WEB=0: web_search INTERDIT. N\'utilise PAS web_search.';

        $imgSuffix = ($mode === 'images' || $mode === 'full')
            ? 'Images: au moins 1 URL exploitable; éviter logos/icônes/vignettes/swatches.'
            : '';

        $parts = [
            $base,
            "MODE={$modeTag} AGGR={$aggrTag} WEB={$webTag}",
            $modeSuffix,
            $aggrSuffix,
            $webSuffix,
            $imgSuffix,
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

    /** @param array<string,mixed> $variantFields @param array<string,mixed> $productContext */
    private function buildUserPromptImageOnlySingleMinimal(array $variantFields, array $productContext): string
    {
        $brand = $this->cleanString($productContext['brand'] ?? null) ?? '';
        $model = $this->cleanString($productContext['name'] ?? null) ?? '';
        $type = $this->cleanString($productContext['productType'] ?? null) ?? '';

        $color = $this->cleanString($variantFields['color'] ?? null);
        if ($color === null || trim($color) === '') {
            $color = $this->cleanString($variantFields['colorCode'] ?? null);
        }
        $color = $color ?? '';

        $size = $this->cleanString($variantFields['size'] ?? null);
        if ($size === null || trim($size) === '') {
            $lw = $variantFields['lensWidthMm'] ?? null;
            if (is_numeric($lw)) {
                $size = (string) ((int) $lw);
            }
        }

        $variant = trim($color . (is_string($size) && trim($size) !== '' ? (' ' . $size) : ''));

        $out = "brand: {$brand}\n";
        $out .= "model: {$model}\n";
        $out .= "variant: {$variant}\n";
        $out .= "type: {$type}\n\n";

        $modelForQ = $this->stripBrandPrefixFromName($model, $brand);
        $query = $this->normalizeQuery(trim("{$brand} {$modelForQ} {$variant} official product image"));
        $out .= "query: {$query}\n\n";
        $out .= "Trouve 1 image produit (photo) correspondant à ce variant. Web Search autorisé si nécessaire (fais le minimum). Retour JSON uniquement.";
        return $out;
    }

    private function schemaImageOnlySingle(): array
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

        $lower = strtolower($u);
        $looksCdn = str_contains($lower, 'cdn') || str_contains($lower, 'cloudfront') || str_contains($lower, 'images') || str_contains($lower, 'img');
        $hasSizeParam = str_contains($lower, 'w=') || str_contains($lower, 'width=') || str_contains($lower, 'h=') || str_contains($lower, 'height=') || str_contains($lower, 'resize') || str_contains($lower, 'format=');

        return $looksCdn && $hasSizeParam;
    }

    /** @param array<string,mixed> $variantFields @param array<string,mixed> $productContext @param list<string> $allowedColors */
    private function buildUserPromptImagesOnly(array $variantFields, array $productContext, array $allowedColors, int $maxImages): string
    {
        $productName = $this->cleanString($productContext['name'] ?? null);
        $productBrand = $this->cleanString($productContext['brand'] ?? null);

        $variantName = $this->cleanString($variantFields['name'] ?? null);
        $variantColor = $this->cleanString($variantFields['color'] ?? null);
        $variantColorCode = $this->cleanString($variantFields['colorCode'] ?? null);

        $productNameForQ = is_string($productName) && is_string($productBrand) ? $this->stripBrandPrefixFromName($productName, $productBrand) : $productName;
        $variantNameForQ = is_string($variantName) && is_string($productBrand) ? $this->stripBrandPrefixFromName($variantName, $productBrand) : $variantName;

        $qParts = array_values(array_filter([
            $productBrand,
            $productNameForQ,
            $variantNameForQ,
            $variantColor,
            $variantColorCode,
        ], static fn ($s) => is_string($s) && trim($s) !== ''));
        $baseQuery = trim(implode(' ', $qParts));
        $q = $baseQuery !== '' ? $this->normalizeQuery($baseQuery . ' product photo') : '';

        $payload = [
            'p' => [
                'brand' => $productBrand ?? '',
                'name' => $productName ?? '',
            ],
            'v' => [
                'name' => $variantName ?? '',
                'color' => $variantColor ?? '',
                'colorCode' => $variantColorCode ?? '',
            ],
            'q' => $q,
            'maxImages' => $maxImages,
            'allowedColors' => $this->pipeList($allowedColors, 40),
        ];

        return $this->jsonPrompt($payload);
    }

    /** @param array<string,mixed> $variantFields @param array<string,mixed> $productContext @param list<string> $allowedColors */
    private function buildUserPrompt(array $variantFields, array $productContext, array $allowedColors): string
    {
        $productName = $this->cleanString($productContext['name'] ?? null);
        $productBrand = $this->cleanString($productContext['brand'] ?? null);
        $productType = $this->cleanString($productContext['productType'] ?? null);

        $variantName = $this->cleanString($variantFields['name'] ?? null);
        $variantColor = $this->cleanString($variantFields['color'] ?? null);
        $variantSize = $this->cleanString($variantFields['size'] ?? null);
        $variantBarcode = $this->cleanString($variantFields['barcode'] ?? null);

        $productNameForQ = is_string($productName) && is_string($productBrand) ? $this->stripBrandPrefixFromName($productName, $productBrand) : $productName;
        $variantNameForQ = is_string($variantName) && is_string($productBrand) ? $this->stripBrandPrefixFromName($variantName, $productBrand) : $variantName;

        $qParts = array_values(array_filter([
            $productBrand,
            $productNameForQ,
            $variantNameForQ,
            $variantColor,
            $variantSize,
            $variantBarcode,
        ], static fn ($s) => is_string($s) && trim($s) !== ''));
        $baseQuery = trim(implode(' ', $qParts));
        $q = $baseQuery !== '' ? $this->normalizeQuery($baseQuery . ' product photo') : '';

        $lensWidthMm = is_numeric($variantFields['lensWidthMm'] ?? null) ? (int) $variantFields['lensWidthMm'] : null;
        $bridgeWidthMm = is_numeric($variantFields['bridgeWidthMm'] ?? null) ? (int) $variantFields['bridgeWidthMm'] : null;
        $templeLengthMm = is_numeric($variantFields['templeLengthMm'] ?? null) ? (int) $variantFields['templeLengthMm'] : null;
        $lensHeightMm = is_numeric($variantFields['lensHeightMm'] ?? null) ? (int) $variantFields['lensHeightMm'] : null;

        $price = is_numeric($variantFields['price'] ?? null) ? (float) $variantFields['price'] : null;
        $stock = is_numeric($variantFields['stock'] ?? null) ? (int) $variantFields['stock'] : null;

        $payload = [
            'p' => [
                'name' => $productName ?? '',
                'brand' => $productBrand ?? '',
                'type' => $productType ?? '',
            ],
            'v' => [
                'name' => $this->cleanString($variantFields['name'] ?? null) ?? '',
                'slug' => $this->cleanString($variantFields['slug'] ?? null) ?? '',
                'sku' => $this->cleanString($variantFields['sku'] ?? null) ?? '',
                'barcode' => $this->cleanString($variantFields['barcode'] ?? null) ?? '',
                'color' => $this->cleanString($variantFields['color'] ?? null) ?? '',
                'colorCode' => $this->cleanString($variantFields['colorCode'] ?? null) ?? '',
                'size' => $this->cleanString($variantFields['size'] ?? null) ?? '',
                'measures' => [
                    'lens' => $lensWidthMm,
                    'bridge' => $bridgeWidthMm,
                    'temple' => $templeLengthMm,
                    'height' => $lensHeightMm,
                ],
                'price' => $price,
                'stock' => $stock,
            ],
            'allowedColors' => $this->pipeList($allowedColors, 60),
            'q' => $q,
        ];

        return $this->jsonPrompt($payload);
    }

    /** @param list<string> $values */
    private function pipeList(array $values, int $maxItems): ?string
    {
        $out = [];
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = $this->cleanString($v);
            if (!is_string($t) || trim($t) === '') {
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
            if ($out !== [] && array_keys($out) === range(0, count($out) - 1)) {
                return array_values($out);
            }
            return $out;
        }
        return null;
    }

    /** @param list<string> $items */
    private function formatListPreview(array $items, int $maxItems): string
    {
        $out = [];
        foreach ($items as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = $this->cleanString($v);
            if (!is_string($t) || trim($t) === '') {
                continue;
            }
            $out[] = $t;
            if (count($out) >= $maxItems) {
                break;
            }
        }

        $s = implode(', ', $out);
        return $s;
    }

    private function schemaFull(int $maxImages): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'VariantEnhance',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'suggested' => $this->schemaSuggestedFields(),
                    'confidenceGlobal' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'uncertainFields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sources' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'maxLength' => 220]],
                    'notes' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 160]],
                    'images' => $this->schemaImagesArray($maxImages),
                ],
                'required' => ['suggested', 'confidenceGlobal', 'uncertainFields', 'sources', 'notes', 'images'],
            ],
        ];
    }

    private function schemaFieldsOnly(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'VariantEnhanceFieldsOnly',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'suggested' => $this->schemaSuggestedFields(),
                    'confidenceGlobal' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'uncertainFields' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'sources' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'maxLength' => 220]],
                    'notes' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 160]],
                ],
                'required' => ['suggested', 'confidenceGlobal', 'uncertainFields', 'sources', 'notes'],
            ],
        ];
    }

    /** @return array<string,float> */
    private function buildConfidenceMapFromGlobal(float $global, array $uncertainFields): array
    {
        $g = max(0.0, min(1.0, $global));

        $uncertain = [];
        foreach ($uncertainFields as $f) {
            if (is_string($f) && trim($f) !== '') {
                $uncertain[trim($f)] = true;
            }
        }

        $map = [];
        foreach (array_keys($this->schemaSuggestedFields()['properties'] ?? []) as $field) {
            if (!is_string($field)) {
                continue;
            }
            $c = $g;
            if (isset($uncertain[$field])) {
                $c = min($c, 0.6);
            }
            $map[$field] = $c;
        }

        return $map;
    }

    private function schemaImagesOnly(int $maxImages): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'VariantEnhanceImagesOnly',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'sources' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'maxLength' => 220]],
                    'notes' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 160]],
                    'images' => $this->schemaImagesArray($maxImages),
                ],
                'required' => ['sources', 'notes', 'images'],
            ],
        ];
    }

    private function schemaSuggestedFields(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => ['string', 'null']],
                'slug' => ['type' => ['string', 'null']],
                'sku' => ['type' => ['string', 'null']],
                'barcode' => ['type' => ['string', 'null']],
                'color' => ['type' => ['string', 'null']],
                'colorCode' => ['type' => ['string', 'null']],
                'size' => ['type' => ['string', 'null']],
                'lensWidthMm' => ['type' => ['integer', 'null']],
                'bridgeWidthMm' => ['type' => ['integer', 'null']],
                'templeLengthMm' => ['type' => ['integer', 'null']],
                'lensHeightMm' => ['type' => ['integer', 'null']],
                'price' => ['type' => ['number', 'null']],
                'stock' => ['type' => ['integer', 'null']],
            ],
            'required' => [
                'name',
                'slug',
                'sku',
                'barcode',
                'color',
                'colorCode',
                'size',
                'lensWidthMm',
                'bridgeWidthMm',
                'templeLengthMm',
                'lensHeightMm',
                'price',
                'stock',
            ],
        ];
    }

    private function schemaConfidenceFields(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'name' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'slug' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'sku' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'barcode' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'color' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'colorCode' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'size' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'lensWidthMm' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'bridgeWidthMm' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'templeLengthMm' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'lensHeightMm' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'price' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'stock' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }

    private function schemaImagesArray(int $maxImages): array
    {
        return [
            'type' => 'array',
            'maxItems' => max(1, min(self::MAX_IMAGES, $maxImages)),
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'url' => ['type' => 'string'],
                    'label' => ['type' => ['string', 'null']],
                    'color' => ['type' => ['string', 'null']],
                ],
                'required' => ['url', 'label', 'color'],
            ],
        ];
    }

    /** @param array<string,mixed> $decoded @param list<string> $allowedColors */
    private function hydrateFull(array $decoded, array $allowedColors, int $maxImages): array
    {
        $suggested = is_array($decoded['suggested'] ?? null) ? $decoded['suggested'] : [];
        $confidenceGlobalRaw = $decoded['confidenceGlobal'] ?? 0;
        $confidenceGlobal = is_numeric($confidenceGlobalRaw) ? (float) $confidenceGlobalRaw : 0.0;
        $uncertainFields = is_array($decoded['uncertainFields'] ?? null) ? $decoded['uncertainFields'] : [];
        $confidence = $this->buildConfidenceMapFromGlobal($confidenceGlobal, $uncertainFields);
        $sources = is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
        $notes = is_array($decoded['notes'] ?? null) ? $decoded['notes'] : [];
        $images = is_array($decoded['images'] ?? null) ? $decoded['images'] : [];

        [$outSuggested, $outConfidence] = $this->hydrateFields($suggested, $confidence, $allowedColors);
        [$outSources, $outNotes] = $this->hydrateMeta($sources, $notes);
        $outImages = $this->hydrateImages($images, $maxImages);

        return [
            'suggested' => $outSuggested,
            'confidence' => $outConfidence,
            'sources' => $outSources,
            'notes' => $outNotes,
            'images' => $outImages,
        ];
    }

    /** @param array<string,mixed> $decoded @param list<string> $allowedColors */
    private function hydrateFieldsOnly(array $decoded, array $allowedColors): array
    {
        $suggested = is_array($decoded['suggested'] ?? null) ? $decoded['suggested'] : [];
        $confidenceGlobalRaw = $decoded['confidenceGlobal'] ?? 0;
        $confidenceGlobal = is_numeric($confidenceGlobalRaw) ? (float) $confidenceGlobalRaw : 0.0;
        $uncertainFields = is_array($decoded['uncertainFields'] ?? null) ? $decoded['uncertainFields'] : [];
        $confidence = $this->buildConfidenceMapFromGlobal($confidenceGlobal, $uncertainFields);
        $sources = is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
        $notes = is_array($decoded['notes'] ?? null) ? $decoded['notes'] : [];

        [$outSuggested, $outConfidence] = $this->hydrateFields($suggested, $confidence, $allowedColors);
        [$outSources, $outNotes] = $this->hydrateMeta($sources, $notes);

        return [
            'suggested' => $outSuggested,
            'confidence' => $outConfidence,
            'sources' => $outSources,
            'notes' => $outNotes,
        ];
    }

    /** @param array<string,mixed> $decoded */
    private function hydrateImagesOnly(array $decoded, int $maxImages): array
    {
            $q = $baseQuery !== '' ? ($baseQuery . ' product photo') : '';
            $q = $this->normalizeQuery($q);
        $sources = is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
        $notes = is_array($decoded['notes'] ?? null) ? $decoded['notes'] : [];
        $images = is_array($decoded['images'] ?? null) ? $decoded['images'] : [];

        [$outSources, $outNotes] = $this->hydrateMeta($sources, $notes);
        $outImages = $this->hydrateImages($images, $maxImages);

        return [
            'sources' => $outSources,
            'notes' => $outNotes,
            'images' => $outImages,
        ];
    }

    /** @param array<string,mixed> $suggested @param array<string,mixed> $confidence @param list<string> $allowedColors @return array{0:array<string,mixed>,1:array<string,float>} */
    private function hydrateFields(array $suggested, array $confidence, array $allowedColors): array
    {
            $q = $baseQuery !== '' ? ($baseQuery . ' product photo') : '';
            $q = $this->normalizeQuery($q);
        $outSuggested = [
            'name' => $this->cleanString($suggested['name'] ?? null),
            'slug' => $this->cleanString($suggested['slug'] ?? null),
            'sku' => $this->cleanString($suggested['sku'] ?? null),
            'barcode' => $this->cleanString($suggested['barcode'] ?? null),
            'color' => $this->normalizeColor($this->cleanString($suggested['color'] ?? null), $allowedColors),
            'colorCode' => $this->cleanString($suggested['colorCode'] ?? null),
            'size' => $this->cleanString($suggested['size'] ?? null),
            'lensWidthMm' => is_numeric($suggested['lensWidthMm'] ?? null) ? (int) $suggested['lensWidthMm'] : null,
            'bridgeWidthMm' => is_numeric($suggested['bridgeWidthMm'] ?? null) ? (int) $suggested['bridgeWidthMm'] : null,
            'templeLengthMm' => is_numeric($suggested['templeLengthMm'] ?? null) ? (int) $suggested['templeLengthMm'] : null,
            'lensHeightMm' => is_numeric($suggested['lensHeightMm'] ?? null) ? (int) $suggested['lensHeightMm'] : null,
            'price' => is_numeric($suggested['price'] ?? null) ? (float) $suggested['price'] : null,
            'stock' => is_numeric($suggested['stock'] ?? null) ? (int) $suggested['stock'] : null,
        ];

        $outConfidence = [];
        foreach (array_keys($outSuggested) as $field) {
            $value = $confidence[$field] ?? 0;
            $outConfidence[$field] = is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.0;
        }

        if (is_string($outSuggested['colorCode'] ?? null) && $outSuggested['colorCode'] !== '') {
            $outSuggested['colorCode'] = $this->colorCatalog->cssValueFor($outSuggested['colorCode']);
        }

        return [$outSuggested, $outConfidence];
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

        if (stripos($name, $brand) === 0) {
            $rest = trim(substr($name, strlen($brand)));
            $rest = preg_replace('/^[\s\-\–\—\:\|]+/u', '', $rest) ?? $rest;
            return $rest !== '' ? $rest : $name;
        }

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

    /** @param list<mixed> $sources @param list<mixed> $notes @return array{0:list<string>,1:list<string>} */
    private function hydrateMeta(array $sources, array $notes): array
    {
        $outSources = array_values(array_unique(array_values(array_filter(array_map([$this, 'cleanString'], $sources), static fn ($s) => is_string($s) && $s !== ''))));
        $outNotes = array_values(array_filter(array_map([$this, 'cleanString'], $notes), static fn ($s) => is_string($s) && $s !== ''));
        return [$outSources, $outNotes];
    }

    /** @param list<mixed> $images @return list<array{url:string,label:?string,color:?string}> */
    private function hydrateImages(array $images, int $maxImages = self::MAX_IMAGES, array $allowedColors = []): array
    {
        $outImages = [];
        $limit = max(1, min(self::MAX_IMAGES, $maxImages));
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            $url = $this->cleanString($img['url'] ?? null);
            if ($url === null) {
                continue;
            }
            $rawColor = $this->cleanString($img['color'] ?? null);
            $color = $this->normalizeColor($rawColor, $allowedColors);
            $outImages[] = [
                'url' => $url,
                'label' => $this->cleanString($img['label'] ?? null),
                'color' => $color,
            ];
            if (count($outImages) >= $limit) {
                break;
            }
        }

        $seen = [];
        return array_values(array_filter($outImages, static function (array $row) use (&$seen): bool {
            $u = (string) ($row['url'] ?? '');
            if ($u === '' || isset($seen[$u])) {
                return false;
            }
            $seen[$u] = true;
            return true;
        }));
    }

    /** @return list<string> */
    private function sanitizeAllowedColors(mixed $value): array
    {
        if (!is_array($value)) {
            return array_keys($this->colorCatalog->choices());
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
            if (count($out) >= 60) {
                break;
            }
        }

        $out = array_values(array_unique($out));
        return $out !== [] ? $out : array_keys($this->colorCatalog->choices());
    }

    private function normalizeColor(?string $color, array $allowedColors): ?string
    {
        if (!is_string($color) || trim($color) === '') {
            return null;
        }

        $label = $this->colorCatalog->labelFor($color);
        if ($label === null || trim($label) === '') {
            return null;
        }

        if ($allowedColors !== [] && !in_array($label, $allowedColors, true)) {
            return $label;
        }

        return $label;
    }

    private function cleanString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        return $v !== '' ? $v : null;
    }

    private function cleanNullableScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $t = trim($value);
            return $t !== '' ? $t : null;
        }
        return null;
    }
}

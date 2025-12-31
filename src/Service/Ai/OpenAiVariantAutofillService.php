<?php

namespace App\Service\Ai;

use App\Entity\ProductVariant;

final class OpenAiVariantAutofillService
{
    public function __construct(
        private readonly OpenAiAdminJsonSchemaService $client,
    ) {
    }

    /**
     * @param list<string> $allowedColors
        * @param array<string, mixed> $options Supported: webSearch(bool), aggressiveness(low|medium|high)
     *
     * @return array{
     *   name:?string,
     *   color:?string,
     *   colorCode:?string,
     *   size:?string,
     *   lensWidthMm:?int,
     *   bridgeWidthMm:?int,
     *   templeLengthMm:?int,
     *   lensHeightMm:?int,
     *   confidence:float,
     *   notes:list<string>
     * }
     */
    public function suggest(ProductVariant $variant, array $allowedColors, array $options = []): array
    {
        $allowedColors = array_values(array_unique(array_values(array_filter(array_map(static fn ($c) => is_string($c) ? trim($c) : '', $allowedColors), static fn ($c) => $c !== ''))));

        $useWebSearch = $this->parseBool($options['webSearch'] ?? false);
        $aggressiveness = is_string($options['aggressiveness'] ?? null) ? (string) $options['aggressiveness'] : 'low';
        if (!in_array($aggressiveness, ['low', 'medium', 'high'], true)) {
            $aggressiveness = 'low';
        }

        $product = $variant->getProducts();
        $productName = $product?->getName();
        $productBrand = $product?->getBrand();
        $productType = $product?->getProductType();

        $system = $this->buildSystemPrompt($aggressiveness, $useWebSearch);

        $userData = [
            'productName' => is_string($productName) ? $productName : '',
            'productBrand' => is_string($productBrand) ? $productBrand : '',
            'productType' => is_string($productType) ? $productType : '',
            'variantName' => (string) ($variant->getName() ?? ''),
            'variantColor' => (string) ($variant->getColor() ?? ''),
            'variantColorCode' => (string) ($variant->getColorCode() ?? ''),
            'variantSize' => (string) ($variant->getSize() ?? ''),
            'lensWidthMm' => $variant->getLensWidthMm(),
            'bridgeWidthMm' => $variant->getBridgeWidthMm(),
            'templeLengthMm' => $variant->getTempleLengthMm(),
            'lensHeightMm' => $variant->getLensHeightMm(),
        ];
        $user = $this->buildUserPromptFromData($userData, $allowedColors);

        $schemaFormat = $this->schemaFormat();

        $decoded = $this->client->request($system, $user, $schemaFormat, [
            'webSearch' => $useWebSearch,
            'action' => 'openai.variant_autofill',
            'temperature' => 0.2,
        ]);

        $cleanStr = static function ($v): ?string {
            if (!is_string($v)) {
                return null;
            }
            $v = trim($v);
            return $v !== '' ? $v : null;
        };

        $result = [
            'name' => $cleanStr($decoded['name'] ?? null),
            'color' => $cleanStr($decoded['color'] ?? null),
            'colorCode' => $cleanStr($decoded['colorCode'] ?? null),
            'size' => $cleanStr($decoded['size'] ?? null),
            'lensWidthMm' => is_numeric($decoded['lensWidthMm'] ?? null) ? (int) $decoded['lensWidthMm'] : null,
            'bridgeWidthMm' => is_numeric($decoded['bridgeWidthMm'] ?? null) ? (int) $decoded['bridgeWidthMm'] : null,
            'templeLengthMm' => is_numeric($decoded['templeLengthMm'] ?? null) ? (int) $decoded['templeLengthMm'] : null,
            'lensHeightMm' => is_numeric($decoded['lensHeightMm'] ?? null) ? (int) $decoded['lensHeightMm'] : null,
            'confidence' => is_numeric($decoded['confidence'] ?? null) ? (float) $decoded['confidence'] : 0.0,
            'notes' => is_array($decoded['notes'] ?? null) ? $decoded['notes'] : [],
        ];

        $result['confidence'] = max(0.0, min(1.0, (float) $result['confidence']));

        if (!is_array($result['notes'])) {
            $result['notes'] = [];
        }
        $result['notes'] = array_values(array_filter(array_map(static fn ($n) => is_string($n) ? trim($n) : '', $result['notes']), static fn ($n) => $n !== ''));

        if ($result['color'] !== null && $allowedColors !== [] && !in_array($result['color'], $allowedColors, true)) {
            $result['confidence'] = min((float) $result['confidence'], 0.6);
        }

        return $result;
    }

    private function buildSystemPrompt(string $aggressiveness, bool $useWebSearch): string
    {
        $aggressivenessHint = match ($aggressiveness) {
            'high' => "Mode agressif: tu peux être plus proactif sur les champs texte (name/couleur/taille) si cohérent, mais ne devine pas les mesures numériques sans indice.",
            'medium' => "Mode équilibré: complète ce qui est raisonnablement inférable.",
            default => "Mode prudent: préfère null dès qu'il y a un doute.",
        };

        $webHint = $useWebSearch
            ? "Tu peux utiliser la recherche web si utile pour valider la terminologie (modèle, coloris), mais ne copie pas de texte long et garde la réponse JSON."
            : "N'utilise pas de recherche web.";

        return <<<TXT
Tu aides un administrateur e-commerce à compléter une fiche variante (couleur/taille/nom/mesures).
Contraintes:
- Réponds STRICTEMENT en JSON selon le schéma.
- Ne devine pas des valeurs numériques si tu n'as pas d'indice: mets null.
- Pour la couleur, choisis de préférence une valeur dans allowedColors.
- Le champ name doit être court (≤ 80 caractères) et peut combiner nom produit + couleur + taille.
$aggressivenessHint
$webHint
TXT;
    }

    /** @param array<string,string> $data @param list<string> $allowedColors */
    private function buildUserPromptFromData(array $data, array $allowedColors): string
    {
        $payload = [
            'p' => [
                'brand' => (string) ($data['productBrand'] ?? ''),
                'name' => (string) ($data['productName'] ?? ''),
                'type' => (string) ($data['productType'] ?? ''),
            ],
            'v' => [
                'name' => (string) ($data['variantName'] ?? ''),
                'color' => (string) ($data['variantColor'] ?? ''),
                'colorCode' => (string) ($data['variantColorCode'] ?? ''),
                'size' => (string) ($data['variantSize'] ?? ''),
                'measures' => [
                    'lens' => (string) ($data['lensWidthMm'] ?? ''),
                    'bridge' => (string) ($data['bridgeWidthMm'] ?? ''),
                    'temple' => (string) ($data['templeLengthMm'] ?? ''),
                    'height' => (string) ($data['lensHeightMm'] ?? ''),
                ],
            ],
            'allowedColors' => $this->pipeList($allowedColors, 40),
        ];

        return $this->jsonPrompt($payload);
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

    /** @param list<string> $values */
    private function pipeList(array $values, int $maxItems): ?string
    {
        $out = [];
        foreach ($values as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
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
            if ($out !== [] && array_keys($out) === range(0, count($out) - 1)) {
                return array_values($out);
            }
            return $out;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function schemaFormat(): array
    {
        return [
            'type' => 'json_schema',
            'name' => 'VariantAutofill',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => ['type' => ['string', 'null']],
                    'color' => ['type' => ['string', 'null']],
                    'colorCode' => ['type' => ['string', 'null']],
                    'size' => ['type' => ['string', 'null']],
                    'lensWidthMm' => ['type' => ['integer', 'null']],
                    'bridgeWidthMm' => ['type' => ['integer', 'null']],
                    'templeLengthMm' => ['type' => ['integer', 'null']],
                    'lensHeightMm' => ['type' => ['integer', 'null']],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'notes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['name', 'color', 'colorCode', 'size', 'lensWidthMm', 'bridgeWidthMm', 'templeLengthMm', 'lensHeightMm', 'confidence', 'notes'],
            ],
        ];
    }

    /** @param list<string> $items */
    private function formatListPreview(array $items, int $maxItems): string
    {
        $out = [];
        foreach ($items as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t === '') {
                continue;
            }
            $out[] = $t;
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return implode(', ', $out);
    }
}

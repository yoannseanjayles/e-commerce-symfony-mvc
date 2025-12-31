<?php

namespace App\Service\Ai;

use App\Entity\Images;

final class OpenAiImageColorTagService
{
    public function __construct(
        private readonly OpenAiAdminJsonSchemaService $client,
    ) {
    }

    /**
     * @param list<string> $allowedColors
     * @return array{colorTag:?string, confidence:float, notes:list<string>}
     */
    public function suggestColorTag(Images $image, array $allowedColors): array
    {
        $allowedColors = array_values(array_unique(array_values(array_filter(array_map(static fn ($c) => is_string($c) ? trim($c) : '', $allowedColors), static fn ($c) => $c !== ''))));

        $system = $this->buildSystemPrompt();

        $productName = $image->getProducts()?->getName();
        $sourceUrl = $image->getSourceUrl();
        $fileName = $image->getName();

        $variantColors = [];
        $product = $image->getProducts();
        if ($product !== null) {
            foreach ($product->getVariants() as $v) {
                if (method_exists($v, 'getColor')) {
                    $c = $v->getColor();
                    if (is_string($c) && trim($c) !== '') {
                        $variantColors[] = trim($c);
                    }
                }
            }
        }
        $variantColors = array_values(array_unique($variantColors));

        $userData = [
            'productName' => is_string($productName) ? $productName : '',
            'imageFilename' => (string) $fileName,
            'imageSourceUrl' => is_string($sourceUrl) ? $sourceUrl : '',
            'variantColors' => $variantColors,
        ];
        $user = $this->buildUserPromptFromData($userData, $allowedColors);

        $schemaFormat = $this->schemaFormat();

        $decoded = $this->client->request($system, $user, $schemaFormat, [
            'action' => 'openai.image_color_tag',
            'temperature' => 0.2,
        ]);

        $colorTag = $decoded['colorTag'] ?? null;
        $confidence = $decoded['confidence'] ?? 0.0;
        $notes = $decoded['notes'] ?? [];

        $colorTag = is_string($colorTag) ? trim($colorTag) : null;
        if ($colorTag === '') {
            $colorTag = null;
        }

        $confidence = is_numeric($confidence) ? (float) $confidence : 0.0;
        $confidence = max(0.0, min(1.0, $confidence));

        if (!is_array($notes)) {
            $notes = [];
        }
        $notes = array_values(array_filter(array_map(static fn ($n) => is_string($n) ? trim($n) : '', $notes), static fn ($n) => $n !== ''));

        // If model returns a color not in allowed list, keep it but lower confidence.
        if ($colorTag !== null && $allowedColors !== [] && !in_array($colorTag, $allowedColors, true)) {
            $confidence = min($confidence, 0.5);
        }

        return [
            'colorTag' => $colorTag,
            'confidence' => $confidence,
            'notes' => $notes,
        ];
    }

    private function buildSystemPrompt(): string
    {
        return <<<TXT
Tu aides un administrateur e-commerce (EasyAdmin) à taguer des images produit.
Objectif: proposer un colorTag court et cohérent pour relier les images à la couleur d'une variante.
Contraintes:
- Réponds STRICTEMENT en JSON selon le schéma.
- Si tu n'es pas sûr, mets colorTag à null.
- Si possible, choisis un colorTag dans la liste fournie (allowedColors).
TXT;
    }

    /** @param array<string,mixed> $data @param list<string> $allowedColors */
    private function buildUserPromptFromData(array $data, array $allowedColors): string
    {
        $variantColors = is_array($data['variantColors'] ?? null) ? $data['variantColors'] : [];

        $payload = [
            'product' => (string) ($data['productName'] ?? ''),
            'image' => [
                'filename' => (string) ($data['imageFilename'] ?? ''),
                'url' => (string) ($data['imageSourceUrl'] ?? ''),
            ],
            'variantColors' => $this->pipeList($variantColors, 25),
            'allowedColors' => $this->pipeList($allowedColors, 40),
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
            'name' => 'ImageColorTagSuggestion',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'colorTag' => ['type' => ['string', 'null']],
                    'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'notes' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['colorTag', 'confidence', 'notes'],
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

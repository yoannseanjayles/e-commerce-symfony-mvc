<?php

namespace App\Service\Catalog;

final class ColorCatalog
{
    /**
     * @return array<string,string> Map label => css value (hex)
     */
    public function choices(): array
    {
        return [
            'Noir' => '#000000',
            'Blanc' => '#ffffff',
            'Gris' => '#808080',
            'Argent' => '#c0c0c0',
            'Doré' => '#d4af37',
            'Bleu' => '#1e90ff',
            'Bleu marine' => '#001f3f',
            'Vert' => '#2ecc40',
            'Rouge' => '#ff4136',
            'Bordeaux' => '#800020',
            'Rose' => '#ff69b4',
            'Violet' => '#b10dc9',
            'Orange' => '#ff851b',
            'Jaune' => '#ffdc00',
            'Marron' => '#8b4513',
            'Beige' => '#f5f5dc',
            'Écaille' => '#7b5b3a',
            'Transparent' => '#ffffff00',
        ];
    }

    public function labelFor(?string $value): ?string
    {
        $value = $this->normalizeValue($value);
        if ($value === null) {
            return null;
        }

        foreach ($this->choices() as $label => $css) {
            if (strcasecmp($value, $css) === 0) {
                return $label;
            }
        }

        // If it's already a label, keep it.
        foreach ($this->choices() as $label => $css) {
            if (strcasecmp($value, $label) === 0) {
                return $label;
            }
        }

        return $value;
    }

    public function cssValueFor(?string $value): ?string
    {
        $value = $this->normalizeValue($value);
        if ($value === null) {
            return null;
        }

        // If it's a known label, convert to css value.
        foreach ($this->choices() as $label => $css) {
            if (strcasecmp($value, $label) === 0) {
                return $css;
            }
        }

        // If it's already a known css value, keep it.
        foreach ($this->choices() as $label => $css) {
            if (strcasecmp($value, $css) === 0) {
                return $css;
            }
        }

        // Fallback: assume user provided a valid CSS color string.
        return $value;
    }

    /** @return list<array{label:string,value:string}> */
    public function choicesAsList(): array
    {
        $out = [];
        foreach ($this->choices() as $label => $value) {
            $out[] = ['label' => $label, 'value' => $value];
        }
        return $out;
    }

    private function normalizeValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim($value);
        return $v === '' ? null : $v;
    }
}

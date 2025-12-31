<?php

namespace App\Twig;

use App\Service\Catalog\ColorCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ColorExtension extends AbstractExtension
{
    public function __construct(private ColorCatalog $colors)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('color_label', [$this, 'labelFor']),
            new TwigFunction('color_hex', [$this, 'cssValueFor']),
            new TwigFunction('color_choices', [$this, 'choicesAsList']),
        ];
    }

    public function labelFor(?string $value): ?string
    {
        return $this->colors->labelFor($value);
    }

    public function cssValueFor(?string $value): ?string
    {
        return $this->colors->cssValueFor($value);
    }

    /** @return list<array{label:string,value:string}> */
    public function choicesAsList(): array
    {
        return $this->colors->choicesAsList();
    }
}

<?php

namespace App\Twig;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;

final class HtmlSanitizerExtension extends AbstractExtension
{
    public function __construct(
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('sanitize_html', [$this, 'sanitizeHtml'], ['is_safe' => ['html']]),
        ];
    }

    public function sanitizeHtml(?string $html): Markup
    {
        $clean = $this->sanitizer->sanitize((string) ($html ?? ''));

        return new Markup($clean, 'UTF-8');
    }
}

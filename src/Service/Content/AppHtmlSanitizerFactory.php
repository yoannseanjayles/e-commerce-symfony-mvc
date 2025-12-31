<?php

namespace App\Service\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

final class AppHtmlSanitizerFactory
{
    public static function create(): HtmlSanitizerInterface
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowElements(['p', 'br', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u', 'a'])
            ->allowAttribute('href', ['a'])
            ->allowAttribute('title', ['a'])
            ->allowAttribute('target', ['a'])
            ->allowAttribute('rel', ['a']);

        return new HtmlSanitizer($config);
    }
}

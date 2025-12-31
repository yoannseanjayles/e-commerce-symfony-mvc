<?php

namespace App\Service\BarcodeLookup;

final class BarcodeLookupResult
{
    /** @param string[] $imageUrls */
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $description,
        public readonly ?string $brand,
        public readonly ?string $color,
        public readonly array $imageUrls,
        public readonly string $source,
    ) {
    }
}

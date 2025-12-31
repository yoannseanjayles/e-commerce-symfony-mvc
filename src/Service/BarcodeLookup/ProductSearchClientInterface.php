<?php

namespace App\Service\BarcodeLookup;

interface ProductSearchClientInterface
{
    /**
     * @return list<array{
     *   source: string,
     *   externalId: ?string,
     *   label: string,
     *   description: ?string,
     *   brand: ?string,
     *   color: ?string,
     *   barcode: ?string,
     *   imageUrl: ?string
     * }>
     */
    public function search(string $query, int $limit = 10): array;
}

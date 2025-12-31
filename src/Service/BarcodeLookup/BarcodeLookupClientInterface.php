<?php

namespace App\Service\BarcodeLookup;

interface BarcodeLookupClientInterface
{
    public function lookup(string $barcode): ?BarcodeLookupResult;
}

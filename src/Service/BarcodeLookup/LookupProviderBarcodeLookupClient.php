<?php

namespace App\Service\BarcodeLookup;

final class LookupProviderBarcodeLookupClient implements BarcodeLookupClientInterface
{
    public function __construct(
        private UpcitemdbBarcodeLookupClient $upcitemdb,
        private WikidataBarcodeLookupClient $wikidata,
        private string $lookupProvider,
    ) {
    }

    public function lookup(string $barcode): ?BarcodeLookupResult
    {
        $provider = strtolower(trim($this->lookupProvider));

        return match ($provider) {
            'upcitemdb' => $this->upcitemdb->lookup($barcode),
            'wikidata' => $this->wikidata->lookup($barcode),
            // default: cascade
            default => $this->upcitemdb->lookup($barcode) ?? $this->wikidata->lookup($barcode),
        };
    }
}

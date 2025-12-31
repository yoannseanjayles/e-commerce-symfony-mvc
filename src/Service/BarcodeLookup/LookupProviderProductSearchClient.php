<?php

namespace App\Service\BarcodeLookup;

final class LookupProviderProductSearchClient implements ProductSearchClientInterface
{
    public function __construct(
        private UpcitemdbProductSearchClient $upcitemdb,
        private WikidataProductSearchClient $wikidata,
        private string $lookupProvider,
    ) {
    }

    public function search(string $query, int $limit = 10): array
    {
        $provider = strtolower(trim($this->lookupProvider));

        return match ($provider) {
            'upcitemdb' => $this->upcitemdb->search($query, $limit),
            'wikidata' => $this->wikidata->search($query, $limit),
            default => $this->cascade($query, $limit),
        };
    }

    private function cascade(string $query, int $limit): array
    {
        $results = [];
        $seen = [];

        foreach ([$this->upcitemdb, $this->wikidata] as $client) {
            foreach ($client->search($query, $limit) as $item) {
                $dedupeKey = $this->dedupeKey($item);
                if ($dedupeKey !== null && isset($seen[$dedupeKey])) {
                    continue;
                }

                if ($dedupeKey !== null) {
                    $seen[$dedupeKey] = true;
                }

                $results[] = $item;
                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * @param array{source:string,externalId:?string,label:string,description:?string,brand:?string,color:?string,barcode:?string,imageUrl:?string} $item
     */
    private function dedupeKey(array $item): ?string
    {
        if (isset($item['barcode']) && is_string($item['barcode']) && trim($item['barcode']) !== '') {
            return 'barcode:' . $item['barcode'];
        }

        if (isset($item['source']) && is_string($item['source']) && isset($item['externalId']) && is_string($item['externalId']) && trim($item['externalId']) !== '') {
            return 'external:' . $item['source'] . ':' . $item['externalId'];
        }

        return null;
    }
}

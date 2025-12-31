<?php

namespace App\Service\BarcodeLookup;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WikidataBarcodeLookupClient implements BarcodeLookupClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    public function lookup(string $barcode): ?BarcodeLookupResult
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $cacheKey = 'lookup.wikidata.barcode.' . preg_replace('/\s+/', '', $barcode);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($barcode): ?BarcodeLookupResult {
            $item->expiresAfter(12 * 60 * 60);

                        $sparql = <<<'SPARQL'
SELECT ?item ?itemLabel ?itemDescription ?brandLabel ?manufacturerLabel ?colorLabel ?image WHERE {
    VALUES ?code { "__CODE__" }
    {
        ?item wdt:P2371 ?code .
    } UNION {
        ?item wdt:P7363 ?code .
    }
    OPTIONAL { ?item wdt:P1716 ?brand . }
    OPTIONAL { ?item wdt:P176 ?manufacturer . }
    OPTIONAL { ?item wdt:P462 ?color . }
    OPTIONAL { ?item wdt:P18 ?image . }
    SERVICE wikibase:label { bd:serviceParam wikibase:language "fr,en". }
}
LIMIT 25
SPARQL;

            $sparql = str_replace('__CODE__', addslashes($barcode), $sparql);

            $response = $this->httpClient->request('GET', 'https://query.wikidata.org/sparql', [
                'query' => [
                    'format' => 'json',
                    'query' => $sparql,
                ],
                'headers' => [
                    'Accept' => 'application/sparql-results+json',
                    'User-Agent' => 'e-commerce-Symfony-6/BarcodeImport (contact: admin)',
                ],
            ]);

            if ($response->getStatusCode() === 429) {
                throw new \RuntimeException('Wikidata: quota dépassé (HTTP 429).');
            }

            $data = $response->toArray(false);
            $bindings = $data['results']['bindings'] ?? [];
            if (!is_array($bindings) || count($bindings) === 0) {
                return null;
            }

            $first = $bindings[0];
            $itemIri = $first['item']['value'] ?? null;
            $name = $first['itemLabel']['value'] ?? null;
            $description = $first['itemDescription']['value'] ?? null;

            $brand = $first['brandLabel']['value'] ?? null;
            if ($brand === null || trim($brand) === '') {
                $brand = $first['manufacturerLabel']['value'] ?? null;
            }

            $color = $first['colorLabel']['value'] ?? null;

            $imageUrls = [];
            $seen = [];
            foreach ($bindings as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($itemIri !== null && ($row['item']['value'] ?? null) !== $itemIri) {
                    continue;
                }

                $imageValue = $row['image']['value'] ?? null;
                if (!is_string($imageValue) || trim($imageValue) === '') {
                    continue;
                }
                $imageValue = trim($imageValue);
                if (isset($seen[$imageValue])) {
                    continue;
                }
                $seen[$imageValue] = true;
                $imageUrls[] = $imageValue;
            }

            return new BarcodeLookupResult(
                name: is_string($name) ? $name : null,
                description: is_string($description) ? $description : null,
                brand: is_string($brand) ? $brand : null,
                color: is_string($color) ? $color : null,
                imageUrls: $imageUrls,
                source: 'wikidata',
            );
        });
    }
}

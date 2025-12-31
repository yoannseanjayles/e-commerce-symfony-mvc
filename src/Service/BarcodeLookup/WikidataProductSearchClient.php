<?php

namespace App\Service\BarcodeLookup;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WikidataProductSearchClient implements ProductSearchClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    /**
        * @return list<array{source: string, externalId: ?string, label: string, description: ?string, brand: ?string, color: ?string, barcode: ?string, imageUrl: ?string}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(20, $limit));

                $cacheKey = 'search.wikidata.q.' . sha1(strtolower($query)) . '.l.' . $limit;

                return $this->cache->get($cacheKey, function (ItemInterface $item) use ($query, $limit): array {
                        $item->expiresAfter(60 * 60);

                        
                        $sparql = <<<'SPARQL'
SELECT ?item ?itemLabel ?itemDescription ?brandLabel ?colorLabel ?barcode ?image WHERE {
  SERVICE wikibase:mwapi {
    bd:serviceParam wikibase:endpoint "www.wikidata.org";
                    wikibase:api "EntitySearch";
                    mwapi:search "__SEARCH__";
                    mwapi:language "fr";
                    mwapi:limit "__LIMIT__".
    ?item wikibase:apiOutputItem mwapi:item.
  }

  OPTIONAL { ?item wdt:P1716 ?brand . }
    OPTIONAL { ?item wdt:P462 ?color . }
  OPTIONAL { ?item wdt:P18 ?image . }

  OPTIONAL { ?item wdt:P2371 ?code1 . }
  OPTIONAL { ?item wdt:P7363 ?code2 . }
  OPTIONAL { ?item wdt:P3962 ?code3 . }
  OPTIONAL { ?item wdt:P454 ?code4 . }
  BIND(COALESCE(?code1, ?code2, ?code3, ?code4) AS ?barcode)

  SERVICE wikibase:label { bd:serviceParam wikibase:language "fr,en". }
}
LIMIT __LIMIT__
SPARQL;

            $sparql = str_replace(
                ['__SEARCH__', '__LIMIT__'],
                [addslashes($query), (string) $limit],
                $sparql,
            );

            $response = $this->httpClient->request('GET', 'https://query.wikidata.org/sparql', [
                'query' => [
                    'format' => 'json',
                    'query' => $sparql,
                ],
                'headers' => [
                    'Accept' => 'application/sparql-results+json',
                    'User-Agent' => 'e-commerce-Symfony-6/ProductImportSearch (contact: admin)',
                ],
            ]);

            if ($response->getStatusCode() === 429) {
                throw new \RuntimeException('Wikidata: quota dépassé (HTTP 429).');
            }

            $data = $response->toArray(false);
            $bindings = $data['results']['bindings'] ?? [];
            if (!is_array($bindings) || count($bindings) === 0) {
                return [];
            }

            $results = [];
            foreach ($bindings as $row) {
                if (!is_array($row)) {
                    continue;
                }

            $label = $row['itemLabel']['value'] ?? null;
            if (!is_string($label) || trim($label) === '') {
                continue;
            }

                $qid = $this->extractQid($row['item']['value'] ?? null);
                if ($qid === null) {
                    continue;
                }

            $description = $row['itemDescription']['value'] ?? null;
            $brand = $row['brandLabel']['value'] ?? null;
            $color = $row['colorLabel']['value'] ?? null;
            $barcode = $row['barcode']['value'] ?? null;

            $imageUrl = $row['image']['value'] ?? null;
            if (!is_string($imageUrl) || trim($imageUrl) === '') {
                $imageUrl = null;
            }

                $results[] = [
                    'source' => 'wikidata',
                    'externalId' => $qid,
                    'label' => $label,
                    'description' => is_string($description) ? $description : null,
                    'brand' => is_string($brand) ? $brand : null,
                    'color' => is_string($color) ? $color : null,
                    'barcode' => is_string($barcode) ? $this->normalizeBarcode($barcode) : null,
                    'imageUrl' => $imageUrl,
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }

            return $results;
        });
    }

    private function extractQid(mixed $itemValue): ?string
    {
        if (!is_string($itemValue)) {
            return null;
        }

        if (preg_match('/\/entity\/(Q\d+)$/', $itemValue, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function normalizeBarcode(string $barcode): string
    {
        $barcode = trim($barcode);
        $barcode = preg_replace('/\D+/', '', $barcode) ?? '';

        return $barcode;
    }
}

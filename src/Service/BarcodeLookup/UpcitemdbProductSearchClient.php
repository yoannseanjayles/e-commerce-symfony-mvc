<?php

namespace App\Service\BarcodeLookup;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UpcitemdbProductSearchClient implements ProductSearchClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    public function search(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(20, $limit));

        $cacheKey = 'search.upcitemdb.q.' . sha1(strtolower($query)) . '.l.' . $limit;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($query, $limit): array {
            $item->expiresAfter(60 * 60);

            $response = $this->httpClient->request('GET', 'https://api.upcitemdb.com/prod/trial/search', [
                'query' => [
                    's' => $query,
                    'type' => 'product',
                    'match_mode' => 0,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'e-commerce-Symfony-6/ProductImportSearch (contact: admin)',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status === 404) {
                return [];
            }
            if ($status === 429) {
                throw new \RuntimeException('UPCitemdb: quota dépassé (HTTP 429).');
            }
            if ($status < 200 || $status >= 300) {
                return [];
            }

            $data = $response->toArray(false);
            $items = $data['items'] ?? null;
            if (!is_array($items) || count($items) === 0) {
                return [];
            }

            $results = [];
            foreach ($items as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $label = $row['title'] ?? null;
                if (!is_string($label) || trim($label) === '') {
                    continue;
                }

                $brand = $row['brand'] ?? null;
                $description = $row['description'] ?? null;

                $barcode = null;
                if (is_string($row['ean'] ?? null)) {
                    $barcode = $this->normalizeBarcode((string) $row['ean']);
                }
                if (($barcode === null || $barcode === '') && is_string($row['upc'] ?? null)) {
                    $barcode = $this->normalizeBarcode((string) $row['upc']);
                }
                if ($barcode === '') {
                    $barcode = null;
                }

                $imageUrl = null;
                $images = $row['images'] ?? null;
                if (is_array($images) && count($images) > 0 && is_string($images[0]) && trim((string) $images[0]) !== '') {
                    $imageUrl = (string) $images[0];
                }

                $results[] = [
                    'source' => 'upcitemdb',
                    'externalId' => null,
                    'label' => $label,
                    'description' => is_string($description) ? $description : null,
                    'brand' => is_string($brand) ? $brand : null,
                    'color' => null,
                    'barcode' => $barcode,
                    'imageUrl' => $imageUrl,
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }

            return $results;
        });
    }

    private function normalizeBarcode(string $barcode): string
    {
        $barcode = trim($barcode);
        $barcode = preg_replace('/\D+/', '', $barcode) ?? '';

        return $barcode;
    }
}

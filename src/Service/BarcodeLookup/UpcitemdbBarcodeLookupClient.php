<?php

namespace App\Service\BarcodeLookup;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UpcitemdbBarcodeLookupClient implements BarcodeLookupClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    public function lookup(string $barcode): ?BarcodeLookupResult
    {
        $barcode = $this->normalizeBarcode($barcode);
        if ($barcode === '') {
            return null;
        }

        $cacheKey = 'lookup.upcitemdb.barcode.' . $barcode;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($barcode): ?BarcodeLookupResult {
            // Keep a decent TTL to protect the free quota.
            $item->expiresAfter(12 * 60 * 60);

            $response = $this->httpClient->request('GET', 'https://api.upcitemdb.com/prod/trial/lookup', [
                'query' => [
                    'upc' => $barcode,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'e-commerce-Symfony-6/ProductImport (contact: admin)',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status === 404) {
                return null;
            }
            if ($status === 429) {
                throw new \RuntimeException('UPCitemdb: quota dépassé (HTTP 429).');
            }
            if ($status < 200 || $status >= 300) {
                return null;
            }

            $data = $response->toArray(false);
            $items = $data['items'] ?? null;
            if (!is_array($items) || count($items) === 0) {
                return null;
            }

            $first = $items[0];
            if (!is_array($first)) {
                return null;
            }

            $name = $first['title'] ?? null;
            $brand = $first['brand'] ?? null;
            $description = $first['description'] ?? null;

            $images = $first['images'] ?? [];
            $imageUrls = [];
            if (is_array($images)) {
                $seen = [];
                foreach ($images as $url) {
                    if (is_string($url) && trim($url) !== '') {
                        $url = trim($url);
                        if (isset($seen[$url])) {
                            continue;
                        }
                        $seen[$url] = true;
                        $imageUrls[] = $url;
                    }
                }
            }

            return new BarcodeLookupResult(
                name: is_string($name) ? $name : null,
                description: is_string($description) ? $description : null,
                brand: is_string($brand) ? $brand : null,
                color: null,
                imageUrls: $imageUrls,
                source: 'upcitemdb',
            );
        });
    }

    private function normalizeBarcode(string $barcode): string
    {
        $barcode = trim($barcode);
        $barcode = preg_replace('/\D+/', '', $barcode) ?? '';

        return $barcode;
    }
}

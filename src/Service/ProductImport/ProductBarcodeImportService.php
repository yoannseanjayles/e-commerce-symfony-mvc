<?php

namespace App\Service\ProductImport;

use App\Entity\Categories;
use App\Entity\Images;
use App\Entity\Products;
use App\Repository\ProductsRepository;
use App\Service\BarcodeLookup\BarcodeLookupClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ProductBarcodeImportService
{
    private const EXTERNAL_SOURCE_WIKIDATA = 'wikidata';

    private const IMPORT_IMAGE_MAX_BYTES = 5_000_000;
    private const IMPORT_IMAGE_TIMEOUT_SECONDS = 8.0;
    private const IMPORT_IMAGE_MAX_DURATION_SECONDS = 12.0;

    public function __construct(
        private ProductsRepository $productsRepository,
        private EntityManagerInterface $entityManager,
        private BarcodeLookupClientInterface $barcodeLookupClient,
        private HttpClientInterface $httpClient,
        private SluggerInterface $slugger,
        private string $projectDir,
    ) {
    }

    /**
     * @return array{product: Products, created: bool, updated: bool, message: string}
     */
    public function import(
        string $barcode,
        Categories $category,
        int $priceCents,
        int $stock,
        bool $updateIfExists = false,
    ): array {
        $barcode = $this->normalizeBarcode($barcode);
        if ($barcode === '') {
            throw new \InvalidArgumentException('Barcode vide.');
        }

        $existing = $this->productsRepository->findOneBy(['barcode' => $barcode]);
        if ($existing instanceof Products && !$updateIfExists) {
            return [
                'product' => $existing,
                'created' => false,
                'updated' => false,
                'message' => 'Produit déjà présent (idempotence) : aucun doublon créé.',
            ];
        }

        $lookup = $this->barcodeLookupClient->lookup($barcode);
        if (!$existing && $lookup === null) {
            throw new \RuntimeException('Aucun produit trouvé pour ce code-barres via le lookup gratuit.');
        }

        $created = false;
        $updated = false;

        $product = $existing ?? new Products();
        if (!$existing) {
            $created = true;
            $product->setBarcode($barcode);
        }

        // Champs obligatoires (toujours écrasés par la saisie admin)
        $product->setCategories($category);
        $product->setPrice($priceCents);
        $product->setStock($stock);

        // Champs importés (on évite d'écraser ce qui a été saisi / curaté)
        if ($lookup !== null) {
            $updated = $this->applyLookupData($product, $lookup->name, $lookup->description, $lookup->brand, $lookup->color) || $updated;
        }

        if ($product->getName() === null || trim($product->getName()) === '') {
            $product->setName('Produit ' . $barcode);
            $updated = true;
        }

        if ($product->getDescription() === null || trim($product->getDescription()) === '') {
            $product->setDescription('Description à compléter.');
            $updated = true;
        }

        if ($product->getSlug() === null || trim($product->getSlug()) === '') {
            $product->setSlug($this->slugger->slug($product->getName())->lower()->toString());
            $updated = true;
        }

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        // Images (si dispo)
        if ($lookup !== null && count($lookup->imageUrls) > 0) {
            $addedImages = $this->importImages($product, $lookup->imageUrls);
            if ($addedImages > 0) {
                $updated = true;
            }
        }

        if ($existing instanceof Products) {
            $message = $updated ? 'Produit existant mis à jour.' : 'Produit existant inchangé.';
        } else {
            $message = 'Produit importé et créé.';
        }

        return [
            'product' => $product,
            'created' => $created,
            'updated' => $updated,
            'message' => $message,
        ];
    }

    /**
     * Import/creates a product from a Wikidata QID (e.g. Q12345).
     * This is useful when the item has no barcode property in Wikidata.
     *
     * @return array{product: Products, created: bool, updated: bool, message: string}
     */
    public function importFromWikidata(
        string $qid,
        Categories $category,
        int $priceCents,
        int $stock,
        bool $updateIfExists = false,
    ): array {
        $qid = $this->normalizeQid($qid);
        if ($qid === '') {
            throw new \InvalidArgumentException('Identifiant externe (Wikidata) invalide.');
        }

        $existing = $this->productsRepository->findOneBy([
            'externalSource' => self::EXTERNAL_SOURCE_WIKIDATA,
            'externalId' => $qid,
        ]);

        if ($existing instanceof Products && !$updateIfExists) {
            return [
                'product' => $existing,
                'created' => false,
                'updated' => false,
                'message' => 'Produit déjà présent (idempotence via source externe) : aucun doublon créé.',
            ];
        }

        $lookupPayload = $this->lookupWikidataItem($qid);
        if (!$existing && $lookupPayload === null) {
            throw new \RuntimeException('Aucun produit trouvé via le lookup gratuit.' );
        }

        $created = false;
        $updated = false;

        $product = $existing ?? new Products();
        if (!$existing) {
            $created = true;
            $product->setExternalSource(self::EXTERNAL_SOURCE_WIKIDATA);
            $product->setExternalId($qid);
        }

        // Champs obligatoires (toujours écrasés par la saisie admin)
        $product->setCategories($category);
        $product->setPrice($priceCents);
        $product->setStock($stock);

        if ($lookupPayload !== null) {
            $lookup = $lookupPayload['lookup'];

            // If we discovered a barcode, store it when empty.
            $barcode = $lookupPayload['barcode'] ?? null;
            if (($product->getBarcode() === null || trim((string) $product->getBarcode()) === '') && is_string($barcode) && trim($barcode) !== '') {
                $product->setBarcode($barcode);
                $updated = true;
            }

            $updated = $this->applyLookupData($product, $lookup->name, $lookup->description, $lookup->brand, $lookup->color) || $updated;

            // Ensure external linkage is set even on updates
            if (($product->getExternalSource() === null || $product->getExternalSource() === '') && $product->getExternalId() === null) {
                $product->setExternalSource(self::EXTERNAL_SOURCE_WIKIDATA);
                $product->setExternalId($qid);
                $updated = true;
            }
        }

        if ($product->getName() === null || trim($product->getName()) === '') {
            $product->setName('Produit ' . $qid);
            $updated = true;
        }

        if ($product->getDescription() === null || trim($product->getDescription()) === '') {
            $product->setDescription('Description à compléter.');
            $updated = true;
        }

        if ($product->getSlug() === null || trim($product->getSlug()) === '') {
            $product->setSlug($this->slugger->slug($product->getName())->lower()->toString());
            $updated = true;
        }

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        if ($lookupPayload !== null) {
            $lookup = $lookupPayload['lookup'];
            if (count($lookup->imageUrls) > 0) {
                $addedImages = $this->importImages($product, $lookup->imageUrls);
                if ($addedImages > 0) {
                    $updated = true;
                }
            }
        }

        if ($existing instanceof Products) {
            $message = $updated ? 'Produit existant mis à jour.' : 'Produit existant inchangé.';
        } else {
            $message = 'Produit importé et créé.';
        }

        return [
            'product' => $product,
            'created' => $created,
            'updated' => $updated,
            'message' => $message,
        ];
    }

    private function normalizeBarcode(string $barcode): string
    {
        $barcode = trim($barcode);
        // Keep digits only (EAN/UPC)
        $barcode = preg_replace('/\D+/', '', $barcode) ?? '';

        return $barcode;
    }

    private function normalizeQid(string $qid): string
    {
        $qid = strtoupper(trim($qid));
        if (preg_match('/^Q\d+$/', $qid) !== 1) {
            return '';
        }

        return $qid;
    }

    /**
     * @return null|array{lookup: \App\Service\BarcodeLookup\BarcodeLookupResult, barcode: ?string}
     */
    private function lookupWikidataItem(string $qid): ?array
    {
        $qid = $this->normalizeQid($qid);
        if ($qid === '') {
            return null;
        }

        $sparql = <<<'SPARQL'
SELECT ?itemLabel ?itemDescription ?brandLabel ?manufacturerLabel ?colorLabel ?image ?barcode WHERE {
  BIND(wd:__QID__ AS ?item)
  OPTIONAL { ?item wdt:P1716 ?brand . }
  OPTIONAL { ?item wdt:P176 ?manufacturer . }
  OPTIONAL { ?item wdt:P462 ?color . }
  OPTIONAL { ?item wdt:P18 ?image . }

  OPTIONAL { ?item wdt:P2371 ?code1 . }
  OPTIONAL { ?item wdt:P7363 ?code2 . }
  OPTIONAL { ?item wdt:P3962 ?code3 . }
  OPTIONAL { ?item wdt:P454 ?code4 . }
  BIND(COALESCE(?code1, ?code2, ?code3, ?code4) AS ?barcode)

  SERVICE wikibase:label { bd:serviceParam wikibase:language "fr,en". }
}
LIMIT 25
SPARQL;

        $sparql = str_replace('__QID__', $qid, $sparql);

        $response = $this->httpClient->request('GET', 'https://query.wikidata.org/sparql', [
            'query' => [
                'format' => 'json',
                'query' => $sparql,
            ],
            'headers' => [
                'Accept' => 'application/sparql-results+json',
                'User-Agent' => 'e-commerce-Symfony-6/ProductImportLookup (contact: admin)',
            ],
        ]);

        $data = $response->toArray(false);
        $bindings = $data['results']['bindings'] ?? [];
        if (!is_array($bindings) || count($bindings) === 0) {
            return null;
        }

        $name = null;
        $description = null;
        $brand = null;
        $color = null;
        $barcode = null;
        $imageUrls = [];

        foreach ($bindings as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($name === null) {
                $nameValue = $row['itemLabel']['value'] ?? null;
                if (is_string($nameValue) && trim($nameValue) !== '') {
                    $name = $nameValue;
                }
            }

            if ($description === null) {
                $descValue = $row['itemDescription']['value'] ?? null;
                if (is_string($descValue) && trim($descValue) !== '') {
                    $description = $descValue;
                }
            }

            if ($brand === null) {
                $brandValue = $row['brandLabel']['value'] ?? null;
                if (!is_string($brandValue) || trim($brandValue) === '') {
                    $brandValue = $row['manufacturerLabel']['value'] ?? null;
                }
                if (is_string($brandValue) && trim($brandValue) !== '') {
                    $brand = $brandValue;
                }
            }

            if ($color === null) {
                $colorValue = $row['colorLabel']['value'] ?? null;
                if (is_string($colorValue) && trim($colorValue) !== '') {
                    $color = $colorValue;
                }
            }

            if ($barcode === null) {
                $barcodeValue = $row['barcode']['value'] ?? null;
                if (is_string($barcodeValue)) {
                    $normalized = $this->normalizeBarcode($barcodeValue);
                    if ($normalized !== '') {
                        $barcode = $normalized;
                    }
                }
            }

            $imageValue = $row['image']['value'] ?? null;
            if (is_string($imageValue) && $imageValue !== '' && !in_array($imageValue, $imageUrls, true)) {
                $imageUrls[] = $imageValue;
            }
        }

        $lookup = new \App\Service\BarcodeLookup\BarcodeLookupResult(
            name: $name,
            description: $description,
            brand: $brand,
            color: $color,
            imageUrls: $imageUrls,
            source: self::EXTERNAL_SOURCE_WIKIDATA,
        );

        return [
            'lookup' => $lookup,
            'barcode' => $barcode,
        ];
    }

    private function applyLookupData(Products $product, ?string $name, ?string $description, ?string $brand, ?string $color): bool
    {
        $changed = false;

        if (($product->getName() === null || trim($product->getName()) === '') && is_string($name) && trim($name) !== '') {
            $product->setName($name);
            $changed = true;
        }

        if (($product->getDescription() === null || trim($product->getDescription()) === '') && is_string($description) && trim($description) !== '') {
            $product->setDescription($description);
            $changed = true;
        }

        if (($product->getBrand() === null || trim((string) $product->getBrand()) === '') && is_string($brand) && trim($brand) !== '') {
            $product->setBrand($brand);
            $changed = true;
        }

        if (($product->getColor() === null || trim((string) $product->getColor()) === '') && is_string($color) && trim($color) !== '') {
            $product->setColor($color);
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param string[] $imageUrls
     */
    private function importImages(Products $product, array $imageUrls): int
    {
        $existingSourceUrls = [];
        foreach ($product->getImages() as $img) {
            if ($img instanceof Images) {
                $url = $img->getSourceUrl();
                if (is_string($url) && $url !== '') {
                    $existingSourceUrls[$url] = true;
                }
            }
        }

        $uploadDir = $this->projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $added = 0;
        foreach ($imageUrls as $url) {
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $url = trim($url);
            if (isset($existingSourceUrls[$url])) {
                continue;
            }

            $fileName = $this->downloadImage($url, $uploadDir);
            if ($fileName === null) {
                continue;
            }

            $image = new Images();
            $image->setName($fileName);
            $image->setSourceUrl($url);
            $product->addImage($image);

            $this->entityManager->persist($image);
            $added++;
        }

        if ($added > 0) {
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        return $added;
    }

    private function downloadImage(string $url, string $uploadDir): ?string
    {
        try {
            if (!$this->isAllowedRemoteImageUrl($url)) {
                return null;
            }

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::IMPORT_IMAGE_TIMEOUT_SECONDS,
                'max_duration' => self::IMPORT_IMAGE_MAX_DURATION_SECONDS,
                'max_redirects' => 0,
                'headers' => [
                    'Accept' => 'image/*',
                    'User-Agent' => 'e-commerce-Symfony-6/ProductBarcodeImportImageFetcher',
                ],
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return null;
            }

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? '';

            // Basic size cap (header-level when available)
            $contentLength = $headers['content-length'][0] ?? null;
            if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::IMPORT_IMAGE_MAX_BYTES) {
                return null;
            }

            $extension = $this->guessExtension($contentType, $url);
            if ($extension === null) {
                return null;
            }

            $bytes = $this->readResponseBytesLimited($response, self::IMPORT_IMAGE_MAX_BYTES);
            if (!is_string($bytes) || $bytes === '') {
                return null;
            }

            // Ensure it is actually an image payload (not HTML with spoofed headers)
            if (@getimagesizefromstring($bytes) === false) {
                return null;
            }

            $fileName = bin2hex(random_bytes(12)) . '.' . $extension;
            $target = $uploadDir . $fileName;
            file_put_contents($target, $bytes, LOCK_EX);

            return $fileName;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isAllowedRemoteImageUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || trim($host) === '') {
            return false;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && !in_array((int) $port, [80, 443], true)) {
            return false;
        }

        $ips = $this->resolveHostIps($host);
        if (count($ips) === 0) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] */
    private function resolveHostIps(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            return [];
        }

        // Host may already be an IP literal
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $ip) {
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $row) {
                $ip6 = $row['ipv6'] ?? null;
                if (is_string($ip6) && $ip6 !== '') {
                    $ips[] = $ip6;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function readResponseBytesLimited(ResponseInterface $response, int $maxBytes): ?string
    {
        if ($maxBytes <= 0) {
            return null;
        }

        $buffer = '';
        $received = 0;

        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                return null;
            }

            $content = $chunk->getContent();
            if ($content === '') {
                continue;
            }

            $len = strlen($content);
            $received += $len;
            if ($received > $maxBytes) {
                return null;
            }

            $buffer .= $content;
        }

        return $buffer;
    }

    private function guessExtension(string $contentType, string $url): ?string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => $this->guessExtensionFromUrl($url),
        };
    }

    private function guessExtensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        return null;
    }
}

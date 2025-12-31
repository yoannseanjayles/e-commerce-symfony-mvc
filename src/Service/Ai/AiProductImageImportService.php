<?php

namespace App\Service\Ai;

use App\Entity\Images;
use App\Entity\Products;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiProductImageImportService
{
    private const MAX_STAGED_DOWNLOADS = 30;
    private const MAX_REDIRECTS = 10;

    /** A browser-like UA helps avoid basic bot blocks on some ecommerce hosts. */
    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private string $projectDir,
        private ?string $allowedDomains,
    ) {
    }

    /**
     * @param list<string> $urls
     *
     * @return array{added:int, skipped:int, errors:list<string>}
     */
    public function addImagesFromUrls(Products $product, array $urls): array
    {
        $items = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            $items[] = ['url' => $url, 'colorTag' => null];
        }

        return $this->addImagesFromItems($product, $items);
    }

    /**
     * @param list<array{url:mixed,colorTag?:mixed}> $items
     *
     * @return array{added:int, skipped:int, errors:list<string>, addedImageIds?:list<int>}
     */
    public function addImagesFromItems(Products $product, array $items): array
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
        $skipped = 0;
        $errors = [];
        $addedImages = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $url = isset($item['url']) && is_string($item['url']) ? trim($item['url']) : '';
            if ($url === '') {
                $skipped++;
                continue;
            }

            $colorTag = isset($item['colorTag']) && is_string($item['colorTag']) ? $this->normalizeColorTag($item['colorTag']) : null;

            if (isset($existingSourceUrls[$url])) {
                $skipped++;
                continue;
            }

            if (!$this->isAllowedUrl($url)) {
                $skipped++;
                $errors[] = 'URL non autorisée: ' . $url;
                continue;
            }

            $download = $this->downloadImage($url, $uploadDir);
            $fileName = is_array($download) ? ($download['fileName'] ?? null) : null;
            if (!is_string($fileName) || $fileName === '') {
                $skipped++;
                $reason = is_array($download) && is_string($download['error'] ?? null) && trim((string) $download['error']) !== ''
                    ? (' (' . trim((string) $download['error']) . ')')
                    : '';
                $errors[] = 'Téléchargement impossible' . $reason . ': ' . $url;
                continue;
            }

            $image = new Images();
            $image->setName($fileName);
            $image->setSourceUrl($url);
            $image->setColorTag($colorTag);
            $product->addImage($image);

            $this->entityManager->persist($image);
            $addedImages[] = $image;
            $added++;
        }

        if ($added > 0) {
            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        $addedImageIds = [];
        foreach ($addedImages as $img) {
            if ($img instanceof Images && $img->getId() !== null) {
                $addedImageIds[] = (int) $img->getId();
            }
        }

        return [
            'added' => $added,
            'skipped' => $skipped,
            'errors' => array_values($errors),
            'addedImageIds' => $addedImageIds,
        ];
    }

    /**
     * Downloads images to the uploads directory without attaching them to a product yet.
     * Useful for the EasyAdmin "new" page (create form).
     *
     * @param list<string> $urls
     * @return array{downloaded:list<array{fileName:string,sourceUrl:string}>, skipped:int, errors:list<string>}
     */
    public function downloadImagesToUploads(array $urls): array
    {
        $items = [];
        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }
            $items[] = ['url' => $url, 'colorTag' => null];
        }

        $result = $this->downloadImagesToUploadsWithMeta($items);

        return [
            'downloaded' => array_values(array_map(static function (array $row): array {
                return [
                    'fileName' => (string) ($row['fileName'] ?? ''),
                    'sourceUrl' => (string) ($row['sourceUrl'] ?? ''),
                ];
            }, $result['downloaded'] ?? [])),
            'skipped' => $result['skipped'] ?? 0,
            'errors' => $result['errors'] ?? [],
        ];
    }

    /**
     * @param list<array{url:mixed,colorTag?:mixed}> $items
     * @return array{downloaded:list<array{fileName:string,sourceUrl:string,colorTag:?string}>, skipped:int, errors:list<string>}
     */
    public function downloadImagesToUploadsWithMeta(array $items): array
    {
        $uploadDir = $this->projectDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $downloaded = [];
        $skipped = 0;
        $errors = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $url = isset($item['url']) && is_string($item['url']) ? trim($item['url']) : '';
            if ($url === '') {
                $skipped++;
                continue;
            }

            $colorTag = isset($item['colorTag']) && is_string($item['colorTag']) ? $this->normalizeColorTag($item['colorTag']) : null;

            if (!$this->isAllowedUrl($url)) {
                $skipped++;
                $errors[] = 'URL non autorisée: ' . $url;
                continue;
            }

            $download = $this->downloadImage($url, $uploadDir);
            $fileName = is_array($download) ? ($download['fileName'] ?? null) : null;
            if (!is_string($fileName) || $fileName === '') {
                $skipped++;
                $reason = is_array($download) && is_string($download['error'] ?? null) && trim((string) $download['error']) !== ''
                    ? (' (' . trim((string) $download['error']) . ')')
                    : '';
                $errors[] = 'Téléchargement impossible' . $reason . ': ' . $url;
                continue;
            }

            $downloaded[] = [
                'fileName' => $fileName,
                'sourceUrl' => $url,
                'colorTag' => $colorTag,
            ];

            if (count($downloaded) >= self::MAX_STAGED_DOWNLOADS) {
                break;
            }
        }

        // De-dup by fileName
        $seen = [];
        $downloaded = array_values(array_filter($downloaded, static function (array $row) use (&$seen): bool {
            $k = (string) ($row['fileName'] ?? '');
            if ($k === '' || isset($seen[$k])) {
                return false;
            }
            $seen[$k] = true;
            return true;
        }));

        return [
            'downloaded' => $downloaded,
            'skipped' => $skipped,
            'errors' => array_values($errors),
        ];
    }

    private function normalizeColorTag(string $colorTag): ?string
    {
        $t = trim($colorTag);
        if ($t === '') {
            return null;
        }

        // Store lowercased for easier matching on the storefront.
        $t = function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);

        // Defensive cap.
        $len = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
        if ($len > 100) {
            $t = function_exists('mb_substr') ? mb_substr($t, 0, 100) : substr($t, 0, 100);
        }

        return $t;
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isPrivateIp($host)) {
                return false;
            }
        }

        $domainsRaw = $this->allowedDomains ?? '';
        $domains = array_values(array_filter(array_map('trim', explode(',', $domainsRaw))));
        if (count($domains) === 0) {
            return true;
        }

        foreach ($domains as $domain) {
            $domain = strtolower($domain);
            if ($domain === '') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE + NO_RES_RANGE returns false for private/reserved.
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * @return array{fileName:?string,error:?string}
     */
    private function downloadImage(string $url, string $uploadDir, int $depth = 0, ?string $referer = null, int $fallbackAttempt = 0): array
    {
        try {
            if (!is_string($referer) || $referer === '') {
                $parts = parse_url($url);
                if (is_array($parts)) {
                    $scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? strtolower($parts['scheme']) : '';
                    $host = isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';
                    if (in_array($scheme, ['http', 'https'], true) && $host !== '') {
                        $referer = $scheme . '://' . $host . '/';
                    }
                }
            }

            $headers = [
                'User-Agent' => self::DEFAULT_USER_AGENT,
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8',
            ];
            if (is_string($referer) && $referer !== '') {
                $headers['Referer'] = $referer;
            }

            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'max_redirects' => self::MAX_REDIRECTS,
                'timeout' => 20,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                if ($status === 404 && $fallbackAttempt < 1) {
                    $alt = $this->shopifySwapFilesProductsPath($url);
                    if (is_string($alt) && $alt !== '' && $alt !== $url && $this->isAllowedUrl($alt)) {
                        return $this->downloadImage($alt, $uploadDir, $depth, $referer, $fallbackAttempt + 1);
                    }
                }
                $hint = '';
                if (in_array($status, [401, 403], true)) {
                    $hint = ' (site protégé / hotlink bloqué: utilisez une URL directe .jpg/.png/.webp)';
                } elseif ($status === 404) {
                    $hint = ' (URL cassée / expirée: la source a peut-être changé)';
                }
                return ['fileName' => null, 'error' => 'HTTP ' . $status . $hint];
            }

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? '';
            $bytes = $response->getContent();
            if (!is_string($bytes) || $bytes === '') {
                return ['fileName' => null, 'error' => 'Réponse vide'];
            }

            // If the URL is an HTML page (product page), try to extract a usable image URL (og:image, twitter:image, etc.)
            // and then download that real image URL.
            $ct = strtolower(trim(explode(';', (string) $contentType)[0] ?? ''));
            if (($ct === 'text/html' || $ct === 'application/xhtml+xml') && $depth < 1) {
                $candidate = $this->extractImageUrlFromHtml($bytes, $url);
                if (is_string($candidate) && $candidate !== '' && $this->isAllowedUrl($candidate)) {
                    return $this->downloadImage($candidate, $uploadDir, $depth + 1, $url, $fallbackAttempt);
                }
                return ['fileName' => null, 'error' => 'HTML sans image exploitable'];
            }

            $extension = $this->guessExtension((string) $contentType, $url);
            if ($extension === null) {
                // Some hosts return odd/missing content-type; if it's HTML-ish, still try 1-level extraction.
                $maybeHtml = preg_match('/^\s*<(?:!doctype\s+html|html\b)/i', $bytes) === 1;
                if ($maybeHtml && $depth < 1) {
                    $candidate = $this->extractImageUrlFromHtml($bytes, $url);
                    if (is_string($candidate) && $candidate !== '' && $this->isAllowedUrl($candidate)) {
                        return $this->downloadImage($candidate, $uploadDir, $depth + 1, $url, $fallbackAttempt);
                    }
                }
                $ctLabel = is_string($contentType) && trim($contentType) !== '' ? trim($contentType) : 'inconnu';
                return ['fileName' => null, 'error' => 'Type non supporté: ' . $ctLabel];
            }

            // Hard cap at ~10MB to avoid abuse.
            if (strlen($bytes) > 10 * 1024 * 1024) {
                return ['fileName' => null, 'error' => 'Fichier trop volumineux'];
            }

            $fileName = bin2hex(random_bytes(12)) . '.' . $extension;
            $target = $uploadDir . $fileName;
            file_put_contents($target, $bytes);

            return ['fileName' => $fileName, 'error' => null];
        } catch (\Throwable $e) {
            $msg = trim($e->getMessage());
            return ['fileName' => null, 'error' => $msg !== '' ? $msg : 'Exception'];
        }
    }

    private function shopifySwapFilesProductsPath(string $url): ?string
    {
        if (!str_contains($url, '/cdn/shop/')) {
            return null;
        }

        if (str_contains($url, '/cdn/shop/files/')) {
            return str_replace('/cdn/shop/files/', '/cdn/shop/products/', $url);
        }

        if (str_contains($url, '/cdn/shop/products/')) {
            return str_replace('/cdn/shop/products/', '/cdn/shop/files/', $url);
        }

        return null;
    }

    private function extractImageUrlFromHtml(string $html, string $baseUrl): ?string
    {
        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Best-effort parsing for messy HTML.
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);

        $candidates = [];

        // og:image
        foreach ($xpath->query('//meta[@property="og:image"]/@content') as $node) {
            $candidates[] = (string) $node->nodeValue;
        }
        // og:image:secure_url
        foreach ($xpath->query('//meta[@property="og:image:secure_url"]/@content') as $node) {
            $candidates[] = (string) $node->nodeValue;
        }
        // twitter:image
        foreach ($xpath->query('//meta[@name="twitter:image"]/@content') as $node) {
            $candidates[] = (string) $node->nodeValue;
        }
        // <link rel="image_src" href="...">
        foreach ($xpath->query('//link[@rel="image_src"]/@href') as $node) {
            $candidates[] = (string) $node->nodeValue;
        }
        // <img srcset="...">
        foreach ($xpath->query('//img[@srcset]/@srcset') as $node) {
            $srcset = trim((string) $node->nodeValue);
            if ($srcset !== '') {
                // Take the first URL in srcset (often the largest is last, but first is better than nothing).
                $first = trim(explode(',', $srcset)[0] ?? '');
                $firstUrl = trim(explode(' ', $first)[0] ?? '');
                if ($firstUrl !== '') {
                    $candidates[] = $firstUrl;
                }
            }
        }
        // Fallback: first image tag
        foreach ($xpath->query('//img[@src]/@src') as $node) {
            $candidates[] = (string) $node->nodeValue;
            if (count($candidates) >= 10) {
                break;
            }
        }

        $resolvedCandidates = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            if (str_starts_with($candidate, 'data:')) {
                continue;
            }

            $resolved = $this->resolveUrl($candidate, $baseUrl);
            if ($resolved !== null) {
                $resolvedCandidates[] = $resolved;
            }
        }

        if (count($resolvedCandidates) === 0) {
            return null;
        }

        $tokens = $this->extractUrlTokens($baseUrl);

        $bestUrl = null;
        $bestScore = null;
        foreach ($resolvedCandidates as $url) {
            $score = $this->scoreCandidateImageUrl($url, $tokens);
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $bestUrl = $url;
            }
        }

        return $bestUrl;
    }

    /** @return list<string> */
    private function extractUrlTokens(string $url): array
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $path = strtolower($path);
        $path = preg_replace('#[^a-z0-9]+#', ' ', $path) ?? $path;
        $parts = array_values(array_filter(array_map('trim', explode(' ', $path)), static fn (string $t): bool => $t !== ''));

        $tokens = [];
        foreach ($parts as $p) {
            if (strlen($p) < 4) {
                continue;
            }
            $tokens[] = $p;
            if (count($tokens) >= 12) {
                break;
            }
        }

        return $tokens;
    }

    /** @param list<string> $tokens */
    private function scoreCandidateImageUrl(string $url, array $tokens): int
    {
        $u = strtolower($url);

        // Penalize obvious non-product assets.
        $bad = ['logo', 'icon', 'sprite', 'badge', 'reseller', 'tracking', 'pixel'];
        $score = 0;
        foreach ($bad as $b) {
            if (str_contains($u, $b)) {
                $score -= 10;
            }
        }

        // Prefer real image-like URLs.
        if (preg_match('#\.(jpg|jpeg|png|webp)(\?|$)#i', $url) === 1) {
            $score += 10;
        }

        // Prefer URLs that match product slug tokens.
        foreach ($tokens as $t) {
            if ($t !== '' && str_contains($u, $t)) {
                $score += 4;
            }
        }

        // Slight preference for typical product keywords.
        $good = ['product', 'products', 'sunglass', 'sunglasses', 'glasses', 'lunettes', 'ray', 'ban', 'rb'];
        foreach ($good as $g) {
            if (str_contains($u, $g)) {
                $score += 1;
            }
        }

        return $score;
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Already absolute
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        // Protocol-relative: //cdn.example.com/img.jpg
        if (str_starts_with($url, '//')) {
            $baseScheme = parse_url($baseUrl, PHP_URL_SCHEME);
            $scheme = is_string($baseScheme) && $baseScheme !== '' ? $baseScheme : 'https';
            return $scheme . ':' . $url;
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts)) {
            return null;
        }

        $scheme = (string) ($baseParts['scheme'] ?? '');
        $host = (string) ($baseParts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = isset($baseParts['port']) ? ':' . (int) $baseParts['port'] : '';
        $origin = $scheme . '://' . $host . $port;

        // Root-relative
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        // Path-relative
        $basePath = (string) ($baseParts['path'] ?? '/');
        if ($basePath === '') {
            $basePath = '/';
        }
        // If base path ends with a file, strip it.
        if (!str_ends_with($basePath, '/')) {
            $basePath = preg_replace('#/[^/]*$#', '/', $basePath) ?? '/';
        }

        return $origin . $basePath . $url;
    }

    private function guessExtension(string $contentType, string $url): ?string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
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
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            return $ext === 'jpeg' ? 'jpg' : $ext;
        }

        return null;
    }
}

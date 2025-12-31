<?php

namespace App\Service\Ai;

use App\Service\Settings\SiteSecretsResolver;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiAdminJsonSchemaService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SiteSecretsResolver $secrets,
        private readonly string $openAiModel,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $aiLogger,
        private readonly RequestStack $requestStack,
        private readonly AiRequestGuard $guard,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $schemaFormat The `text.format` payload (json_schema) for the /v1/responses API.
    * @param array<string, mixed> $options Supported: webSearch(bool), action(string), temperature(float), maxOutputTokens(int)
     * @return array<string, mixed>
     */
    public function request(string $systemPrompt, string $userPrompt, array $schemaFormat, array $options = []): array
    {
        $apiKey = $this->secrets->getOpenAiApiKey();
        if (trim($apiKey) === '') {
            throw new \RuntimeException('OPENAI_API_KEY manquante (clé vide).');
        }

        $start = microtime(true);

        $requestBody = [
            'model' => $this->openAiModel,
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        ['type' => 'input_text', 'text' => $systemPrompt],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $userPrompt],
                    ],
                ],
            ],
            'text' => [
                'format' => $schemaFormat,
            ],
        ];

        $temperature = $options['temperature'] ?? null;
        if (is_numeric($temperature)) {
            $t = (float) $temperature;
            $requestBody['temperature'] = max(0.0, min(1.0, $t));
        }

        $maxOutputTokens = $options['maxOutputTokens'] ?? null;
        if (is_numeric($maxOutputTokens)) {
            $m = (int) $maxOutputTokens;
            if ($m > 0) {
                $requestBody['max_output_tokens'] = $m;
            }
        }

        $useWebSearch = (bool) ($options['webSearch'] ?? false);
        if ($useWebSearch) {
            $requestBody['tools'] = [
                ['type' => 'web_search'],
            ];
            $requestBody['tool_choice'] = 'auto';
        }

        $request = $this->requestStack->getCurrentRequest();
        $route = $request?->attributes->get('_route');
        $route = is_string($route) ? $route : null;
        $path = $request?->getPathInfo();
        $method = $request?->getMethod();

        $schemaName = isset($schemaFormat['name']) && is_string($schemaFormat['name']) ? $schemaFormat['name'] : null;
        $action = isset($options['action']) && is_string($options['action']) && trim($options['action']) !== ''
            ? trim($options['action'])
            : ($schemaName ?? 'openai.json_schema');

        $cacheEnabled = $this->secrets->getAiCacheEnabled();
        $cacheTtlSeconds = $this->secrets->getAiCacheTtlSeconds();
        $cachedItem = null;
        if ($cacheEnabled) {
            $cacheKey = 'openai_json_schema_' . md5(json_encode([
                'model' => $this->openAiModel,
                'schema' => $schemaFormat,
                'webSearch' => $useWebSearch,
                'temperature' => is_numeric($temperature) ? (float) $temperature : null,
                'max_output_tokens' => is_numeric($maxOutputTokens) ? (int) $maxOutputTokens : null,
                'system' => $systemPrompt,
                'user' => $userPrompt,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            $cachedItem = $this->cache->getItem($cacheKey);
            if ($cachedItem->isHit()) {
                $this->aiLogger->info('openai.request.cache_hit', [
                    'action' => $action,
                    'schema' => $schemaName,
                    'model' => $this->openAiModel,
                    'web_search' => $useWebSearch,
                    'route' => $route,
                    'path' => $path,
                    'method' => $method,
                ]);

                $hit = $cachedItem->get();
                if (is_array($hit)) {
                    return $hit;
                }
            }
        }

        $this->guard->enforce($action, $useWebSearch);

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
            ]);
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->aiLogger->error('openai.request.exception', [
                'action' => $action,
                'schema' => $schemaName,
                'model' => $this->openAiModel,
                'web_search' => $useWebSearch,
                'route' => $route,
                'path' => $path,
                'method' => $method,
                'duration_ms' => $durationMs,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $raw = $response->getContent(false);
            $message = null;

            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $err = $decoded['error'] ?? null;
                    if (is_array($err) && isset($err['message']) && is_string($err['message'])) {
                        $message = trim($err['message']);
                    }
                }
            }

            if ($message === null || $message === '') {
                $message = 'OpenAI API error (HTTP ' . $status . ').';
            } else {
                $message = 'OpenAI API error (HTTP ' . $status . '): ' . $message;
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $this->aiLogger->warning('openai.request.http_error', [
                'action' => $action,
                'schema' => $schemaName,
                'model' => $this->openAiModel,
                'web_search' => $useWebSearch,
                'route' => $route,
                'path' => $path,
                'method' => $method,
                'http_status' => $status,
                'duration_ms' => $durationMs,
                'error' => $message,
            ]);

            throw new \RuntimeException($message);
        }

        $data = $response->toArray(false);
        $json = $this->extractJsonText($data);

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : null;
        $inputTokens = is_array($usage) && isset($usage['input_tokens']) && is_numeric($usage['input_tokens']) ? (int) $usage['input_tokens'] : null;
        $outputTokens = is_array($usage) && isset($usage['output_tokens']) && is_numeric($usage['output_tokens']) ? (int) $usage['output_tokens'] : null;
        $totalTokens = is_array($usage) && isset($usage['total_tokens']) && is_numeric($usage['total_tokens']) ? (int) $usage['total_tokens'] : null;

        $this->aiLogger->info('openai.request.ok', [
            'action' => $action,
            'schema' => $schemaName,
            'model' => $this->openAiModel,
            'web_search' => $useWebSearch,
            'route' => $route,
            'path' => $path,
            'method' => $method,
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $totalTokens,
        ]);

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON from model.');
        }

        if ($cacheEnabled && $cachedItem !== null) {
            $cachedItem->set($decoded);
            $cachedItem->expiresAfter($cacheTtlSeconds);
            $this->cache->save($cachedItem);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function extractJsonText(array $data): string
    {
        if (isset($data['output_text']) && is_string($data['output_text']) && trim($data['output_text']) !== '') {
            return (string) $data['output_text'];
        }

        $candidates = [];
        $refusal = null;
        $output = $data['output'] ?? null;
        if (is_array($output)) {
            foreach ($output as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $content = $item['content'] ?? null;
                if (!is_array($content)) {
                    continue;
                }
                foreach ($content as $c) {
                    if (!is_array($c)) {
                        continue;
                    }

                    if ($refusal === null && (($c['type'] ?? null) === 'refusal') && isset($c['refusal']) && is_string($c['refusal'])) {
                        $refusal = trim($c['refusal']);
                    }
                    if (isset($c['text']) && is_string($c['text'])) {
                        $candidates[] = $c['text'];
                    }
                    if (isset($c['output_text']) && is_string($c['output_text'])) {
                        $candidates[] = $c['output_text'];
                    }
                }
            }
        }

        if (is_string($refusal) && $refusal !== '') {
            throw new \RuntimeException('OpenAI refusal: ' . $refusal);
        }

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && str_starts_with($candidate, '{')) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to extract JSON from OpenAI response.');
    }

}

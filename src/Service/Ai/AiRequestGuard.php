<?php

namespace App\Service\Ai;

use App\Service\Settings\SiteSecretsResolver;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class AiRequestGuard
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly SiteSecretsResolver $secrets,
        #[Autowire(service: 'monolog.logger.ai')]
        private readonly LoggerInterface $aiLogger,
    ) {
    }

    public function enforce(string $action, bool $webSearch): void
    {
        if (!$this->secrets->getAiGuardEnabled()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $route = $request?->attributes->get('_route');
        $route = is_string($route) ? $route : 'unknown_route';

        $userKey = $this->resolveUserKey();

        $minuteKey = $this->buildKey('m', $userKey, $route);
        $dayKey = $this->buildKey('d', $userKey, $route);
        $dayWebKey = $this->buildKey('dw', $userKey, $route);

        $minute = $this->incrementCounter($minuteKey, 90);
        $day = $this->incrementCounter($dayKey, 60 * 60 * 26);
        $dayWeb = $webSearch ? $this->incrementCounter($dayWebKey, 60 * 60 * 26) : null;

        $maxPerMinute = $this->secrets->getAiMaxPerMinute();
        $maxPerDay = $this->secrets->getAiMaxPerDay();
        $maxWebSearchPerDay = $this->secrets->getAiMaxWebSearchPerDay();

        $limited = $minute > $maxPerMinute || $day > $maxPerDay || ($webSearch && is_int($dayWeb) && $dayWeb > $maxWebSearchPerDay);
        if ($limited) {
            $this->aiLogger->warning('openai.guard.rate_limited', [
                'action' => $action,
                'route' => $route,
                'user' => $userKey,
                'web_search' => $webSearch,
                'count_minute' => $minute,
                'count_day' => $day,
                'count_day_web_search' => $dayWeb,
                'limit_minute' => $maxPerMinute,
                'limit_day' => $maxPerDay,
                'limit_day_web_search' => $maxWebSearchPerDay,
            ]);

            throw new TooManyRequestsHttpException(60, 'Trop de requêtes IA. Réessaie dans 1 minute.');
        }
    }

    private function resolveUserKey(): string
    {
        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return 'anon';
        }

        $user = $token->getUser();
        if (is_string($user) && $user !== '') {
            return $user;
        }

        if (is_object($user)) {
            if (method_exists($user, 'getUserIdentifier')) {
                $id = $user->getUserIdentifier();
                if (is_string($id) && trim($id) !== '') {
                    return trim($id);
                }
            }
            if (method_exists($user, 'getId')) {
                $id = $user->getId();
                if (is_int($id) || (is_string($id) && trim($id) !== '')) {
                    return 'id:' . (string) $id;
                }
            }
        }

        return 'unknown_user';
    }

    private function buildKey(string $bucket, string $userKey, string $route): string
    {
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $minute = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i');

        if ($bucket === 'm') {
            return 'ai_guard:' . $day . ':' . $minute . ':' . $userKey . ':' . $route;
        }

        return 'ai_guard:' . $day . ':' . $bucket . ':' . $userKey . ':' . $route;
    }

    private function incrementCounter(string $key, int $ttlSeconds): int
    {
        $cacheKey = 'ai_guard_' . md5($key);

        $item = $this->cache->getItem($cacheKey);
        $value = 0;
        if ($item->isHit()) {
            $raw = $item->get();
            $value = is_numeric($raw) ? (int) $raw : 0;
        }

        $value++;
        $item->set($value);
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);

        return $value;
    }
}

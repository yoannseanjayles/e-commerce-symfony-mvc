<?php

namespace App\Service\Settings;

use App\Repository\SiteSettingsRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SiteSecretsResolver
{
    private bool $settingsLoaded = false;
    private ?\App\Entity\SiteSettings $settings = null;

    public function __construct(
        private readonly SiteSettingsRepository $siteSettingsRepository,
        #[Autowire('%env(default::OPENAI_API_KEY)%')]
        private readonly ?string $defaultOpenAiApiKey = null,
        #[Autowire('%env(default::STRIPE_SECRET_KEY)%')]
        private readonly ?string $defaultStripeSecretKey = null,
        #[Autowire('%env(default::STRIPE_WEBHOOK_SECRET)%')]
        private readonly ?string $defaultStripeWebhookSecret = null,
        #[Autowire('%env(default::STRIPE_CURRENCY)%')]
        private readonly ?string $defaultStripeCurrency = null,
    ) {
    }

    private function getSettings(): ?\App\Entity\SiteSettings
    {
        if (!$this->settingsLoaded) {
            try {
                $this->settings = $this->siteSettingsRepository->findSettings();
            } catch (\Throwable) {
                $this->settings = null;
            }

            $this->settingsLoaded = true;
        }

        return $this->settings;
    }

    private function firstNonEmpty(?string $override, string $fallback): string
    {
        $override = is_string($override) ? trim($override) : '';
        if ($override !== '') {
            return $override;
        }

        return $fallback;
    }

    /**
     * Reads a value from the runtime environment without requiring Symfony's env processors.
     * Returns null when missing or empty.
     */
    private function readEnvNonEmpty(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Fallback chain for environments where the variable name differs.
     */
    private function resolveEnvFallback(string $primary, array $alternatives, ?string $injected): string
    {
        $injected = is_string($injected) ? trim($injected) : '';
        if ($injected !== '') {
            return $injected;
        }

        $direct = $this->readEnvNonEmpty($primary);
        if ($direct !== null) {
            return $direct;
        }

        foreach ($alternatives as $alt) {
            $candidate = $this->readEnvNonEmpty($alt);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return '';
    }

    public function getOpenAiApiKey(): string
    {
        $override = $this->getSettings()?->getOpenAiApiKeyOverride();

        $fallback = $this->resolveEnvFallback('OPENAI_API_KEY', ['OPENAI_APIKEY', 'OPENAIAPIKEY'], $this->defaultOpenAiApiKey);

        return $this->firstNonEmpty($override, $fallback);
    }

    public function getStripeSecretKey(): string
    {
        $override = $this->getSettings()?->getStripeSecretKeyOverride();

        $fallback = $this->resolveEnvFallback('STRIPE_SECRET_KEY', ['STRIPE_SECRETKEY', 'STRIPE_SECRET'], $this->defaultStripeSecretKey);

        return $this->firstNonEmpty($override, $fallback);
    }

    public function getStripeWebhookSecret(): string
    {
        $override = $this->getSettings()?->getStripeWebhookSecretOverride();

        $fallback = $this->resolveEnvFallback('STRIPE_WEBHOOK_SECRET', ['STRIPE_WEBHOOKSECRET'], $this->defaultStripeWebhookSecret);

        return $this->firstNonEmpty($override, $fallback);
    }

    public function getStripeCurrency(): string
    {
        $currency = is_string($this->defaultStripeCurrency) ? trim($this->defaultStripeCurrency) : '';
        if ($currency === '') {
            $currency = 'eur';
        }

        return $currency;
    }

    public function getAiGuardEnabled(): bool
    {
        $override = $this->getSettings()?->getAiGuardEnabledOverride();
        if (is_bool($override)) {
            return $override;
        }

        $raw = $this->readEnvNonEmpty('AI_GUARD_ENABLED');
        return $this->parseBool($raw ?? '', true);
    }

    public function getAiMaxPerMinute(): int
    {
        $override = $this->getSettings()?->getAiMaxPerMinuteOverride();
        if (is_int($override) && $override > 0) {
            return $override;
        }

        $raw = $this->readEnvNonEmpty('AI_MAX_PER_MINUTE');
        return $this->parsePositiveInt($raw ?? '', 20);
    }

    public function getAiMaxPerDay(): int
    {
        $override = $this->getSettings()?->getAiMaxPerDayOverride();
        if (is_int($override) && $override > 0) {
            return $override;
        }

        $raw = $this->readEnvNonEmpty('AI_MAX_PER_DAY');
        return $this->parsePositiveInt($raw ?? '', 400);
    }

    public function getAiMaxWebSearchPerDay(): int
    {
        $override = $this->getSettings()?->getAiMaxWebSearchPerDayOverride();
        if (is_int($override) && $override > 0) {
            return $override;
        }

        $raw = $this->readEnvNonEmpty('AI_MAX_WEB_SEARCH_PER_DAY');
        return $this->parsePositiveInt($raw ?? '', 80);
    }

    public function getAiCacheEnabled(): bool
    {
        $override = $this->getSettings()?->getAiCacheEnabledOverride();
        if (is_bool($override)) {
            return $override;
        }

        $raw = $this->readEnvNonEmpty('AI_CACHE_ENABLED');
        return $this->parseBool($raw ?? '', true);
    }

    public function getAiCacheTtlSeconds(): int
    {
        $override = $this->getSettings()?->getAiCacheTtlSecondsOverride();
        if (is_int($override) && $override > 0) {
            return $override;
        }

        $raw = $this->readEnvNonEmpty('AI_CACHE_TTL_SECONDS');
        return $this->parsePositiveInt($raw ?? '', 300);
    }

    private function parseBool(string $raw, bool $fallback): bool
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $fallback;
    }

    private function parsePositiveInt(string $raw, int $fallback): int
    {
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return $fallback;
        }

        $value = (int) $raw;
        return $value > 0 ? $value : $fallback;
    }
}

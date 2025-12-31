<?php

namespace App\Twig;

use App\Repository\SiteSettingsRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SiteSettingsExtension extends AbstractExtension
{
    public function __construct(
        private SiteSettingsRepository $siteSettingsRepository
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_settings', [$this, 'getSiteSettings']),
        ];
    }

    public function getSiteSettings()
    {
        return $this->siteSettingsRepository->findSettings();
    }
}

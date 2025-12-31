<?php

namespace App\Entity;

use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteSettingsRepository::class)]
class SiteSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoHeader = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoLoader = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoFooter = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteFavicon = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sitePhone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $siteAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $siteDescription = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facebookUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $twitterUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $instagramUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pinterestUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tiktokUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $youtubeUrl = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $stripeEnabled = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $maintenanceEnabled = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $openAiApiKeyOverride = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $stripeSecretKeyOverride = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $stripeWebhookSecretOverride = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $aiGuardEnabledOverride = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $aiMaxPerMinuteOverride = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $aiMaxPerDayOverride = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $aiMaxWebSearchPerDayOverride = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $aiCacheEnabledOverride = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $aiCacheTtlSecondsOverride = null;

    public function __toString(): string
    {
        return 'Paramètres du site';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLogoHeader(): ?string
    {
        return $this->logoHeader;
    }

    public function setLogoHeader(?string $logoHeader): static
    {
        $this->logoHeader = $logoHeader;

        return $this;
    }

    public function getLogoLoader(): ?string
    {
        return $this->logoLoader;
    }

    public function setLogoLoader(?string $logoLoader): static
    {
        $this->logoLoader = $logoLoader;

        return $this;
    }

    public function getLogoFooter(): ?string
    {
        return $this->logoFooter;
    }

    public function setLogoFooter(?string $logoFooter): static
    {
        $this->logoFooter = $logoFooter;

        return $this;
    }

    public function getSiteName(): ?string
    {
        return $this->siteName;
    }

    public function setSiteName(?string $siteName): static
    {
        $this->siteName = $siteName;

        return $this;
    }

    public function getSiteEmail(): ?string
    {
        return $this->siteEmail;
    }

    public function setSiteEmail(?string $siteEmail): static
    {
        $this->siteEmail = $siteEmail;

        return $this;
    }

    public function getSitePhone(): ?string
    {
        return $this->sitePhone;
    }

    public function setSitePhone(?string $sitePhone): static
    {
        $this->sitePhone = $sitePhone;

        return $this;
    }

    public function getSiteAddress(): ?string
    {
        return $this->siteAddress;
    }

    public function setSiteAddress(?string $siteAddress): static
    {
        $this->siteAddress = $siteAddress;

        return $this;
    }

    public function getSiteDescription(): ?string
    {
        return $this->siteDescription;
    }

    public function setSiteDescription(?string $siteDescription): static
    {
        $this->siteDescription = $siteDescription;

        return $this;
    }

    public function getFacebookUrl(): ?string
    {
        return $this->facebookUrl;
    }

    public function setFacebookUrl(?string $facebookUrl): static
    {
        $this->facebookUrl = $facebookUrl;

        return $this;
    }

    public function getTwitterUrl(): ?string
    {
        return $this->twitterUrl;
    }

    public function setTwitterUrl(?string $twitterUrl): static
    {
        $this->twitterUrl = $twitterUrl;

        return $this;
    }

    public function getInstagramUrl(): ?string
    {
        return $this->instagramUrl;
    }

    public function setInstagramUrl(?string $instagramUrl): static
    {
        $this->instagramUrl = $instagramUrl;

        return $this;
    }

    public function getPinterestUrl(): ?string
    {
        return $this->pinterestUrl;
    }

    public function setPinterestUrl(?string $pinterestUrl): static
    {
        $this->pinterestUrl = $pinterestUrl;

        return $this;
    }

    public function getTiktokUrl(): ?string
    {
        return $this->tiktokUrl;
    }

    public function setTiktokUrl(?string $tiktokUrl): static
    {
        $this->tiktokUrl = $tiktokUrl;

        return $this;
    }

    public function getYoutubeUrl(): ?string
    {
        return $this->youtubeUrl;
    }

    public function setYoutubeUrl(?string $youtubeUrl): static
    {
        $this->youtubeUrl = $youtubeUrl;

        return $this;
    }

    public function isStripeEnabled(): bool
    {
        return $this->stripeEnabled;
    }

    public function setStripeEnabled(bool $stripeEnabled): static
    {
        $this->stripeEnabled = $stripeEnabled;

        return $this;
    }

    public function isMaintenanceEnabled(): bool
    {
        return $this->maintenanceEnabled;
    }

    public function setMaintenanceEnabled(bool $maintenanceEnabled): static
    {
        $this->maintenanceEnabled = $maintenanceEnabled;

        return $this;
    }

    public function getSiteTitle(): ?string
    {
        return $this->siteTitle;
    }

    public function setSiteTitle(?string $siteTitle): static
    {
        $this->siteTitle = $siteTitle;

        return $this;
    }

    public function getSiteFavicon(): ?string
    {
        return $this->siteFavicon;
    }

    public function setSiteFavicon(?string $siteFavicon): static
    {
        $this->siteFavicon = $siteFavicon;

        return $this;
    }

    public function getOpenAiApiKeyOverride(): ?string
    {
        return $this->openAiApiKeyOverride;
    }

    public function setOpenAiApiKeyOverride(?string $openAiApiKeyOverride): static
    {
        if (is_string($openAiApiKeyOverride)) {
            $openAiApiKeyOverride = trim($openAiApiKeyOverride);
            if ($openAiApiKeyOverride === '') {
                return $this;
            }
        }

        $this->openAiApiKeyOverride = $openAiApiKeyOverride;

        return $this;
    }

    public function getStripeSecretKeyOverride(): ?string
    {
        return $this->stripeSecretKeyOverride;
    }

    public function setStripeSecretKeyOverride(?string $stripeSecretKeyOverride): static
    {
        if (is_string($stripeSecretKeyOverride)) {
            $stripeSecretKeyOverride = trim($stripeSecretKeyOverride);
            if ($stripeSecretKeyOverride === '') {
                return $this;
            }
        }

        $this->stripeSecretKeyOverride = $stripeSecretKeyOverride;

        return $this;
    }

    public function getStripeWebhookSecretOverride(): ?string
    {
        return $this->stripeWebhookSecretOverride;
    }

    public function setStripeWebhookSecretOverride(?string $stripeWebhookSecretOverride): static
    {
        if (is_string($stripeWebhookSecretOverride)) {
            $stripeWebhookSecretOverride = trim($stripeWebhookSecretOverride);
            if ($stripeWebhookSecretOverride === '') {
                return $this;
            }
        }

        $this->stripeWebhookSecretOverride = $stripeWebhookSecretOverride;

        return $this;
    }

    public function getAiGuardEnabledOverride(): ?bool
    {
        return $this->aiGuardEnabledOverride;
    }

    public function setAiGuardEnabledOverride(?bool $aiGuardEnabledOverride): static
    {
        $this->aiGuardEnabledOverride = $aiGuardEnabledOverride;

        return $this;
    }

    public function getAiMaxPerMinuteOverride(): ?int
    {
        return $this->aiMaxPerMinuteOverride;
    }

    public function setAiMaxPerMinuteOverride(?int $aiMaxPerMinuteOverride): static
    {
        $this->aiMaxPerMinuteOverride = $aiMaxPerMinuteOverride;

        return $this;
    }

    public function getAiMaxPerDayOverride(): ?int
    {
        return $this->aiMaxPerDayOverride;
    }

    public function setAiMaxPerDayOverride(?int $aiMaxPerDayOverride): static
    {
        $this->aiMaxPerDayOverride = $aiMaxPerDayOverride;

        return $this;
    }

    public function getAiMaxWebSearchPerDayOverride(): ?int
    {
        return $this->aiMaxWebSearchPerDayOverride;
    }

    public function setAiMaxWebSearchPerDayOverride(?int $aiMaxWebSearchPerDayOverride): static
    {
        $this->aiMaxWebSearchPerDayOverride = $aiMaxWebSearchPerDayOverride;

        return $this;
    }

    public function getAiCacheEnabledOverride(): ?bool
    {
        return $this->aiCacheEnabledOverride;
    }

    public function setAiCacheEnabledOverride(?bool $aiCacheEnabledOverride): static
    {
        $this->aiCacheEnabledOverride = $aiCacheEnabledOverride;

        return $this;
    }

    public function getAiCacheTtlSecondsOverride(): ?int
    {
        return $this->aiCacheTtlSecondsOverride;
    }

    public function setAiCacheTtlSecondsOverride(?int $aiCacheTtlSecondsOverride): static
    {
        $this->aiCacheTtlSecondsOverride = $aiCacheTtlSecondsOverride;

        return $this;
    }
}

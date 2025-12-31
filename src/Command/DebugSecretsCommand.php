<?php

namespace App\Command;

use App\Repository\SiteSettingsRepository;
use App\Service\Settings\SiteSecretsResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:debug:secrets',
    description: 'Debug effective secrets resolution (SiteSettings override vs environment), without printing secret values.',
)]
final class DebugSecretsCommand extends Command
{
    public function __construct(
        private readonly SiteSettingsRepository $siteSettingsRepository,
        private readonly SiteSecretsResolver $secrets,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $settings = null;
        try {
            $settings = $this->siteSettingsRepository->findSettings();
        } catch (\Throwable $e) {
            $settings = null;
            $io->warning('Unable to load SiteSettings from DB (likely missing migrations). Error: ' . $e->getMessage());
        }

        $io->title('Secrets debug (no values printed)');

        $io->section('SiteSettings');
        if (!$settings) {
            $io->warning('No SiteSettings row found (repository returned null). Overrides cannot be used.');
        } else {
            $io->success('SiteSettings row found (id=' . (string) $settings->getId() . ').');
        }

        $openAiOverride = $settings ? trim((string) ($settings->getOpenAiApiKeyOverride() ?? '')) : '';
        $stripeSecretOverride = $settings ? trim((string) ($settings->getStripeSecretKeyOverride() ?? '')) : '';
        $stripeWebhookOverride = $settings ? trim((string) ($settings->getStripeWebhookSecretOverride() ?? '')) : '';

        $io->section('OpenAI');
        $io->listing([
            'Override set in SiteSettings: ' . ($openAiOverride !== '' ? 'YES' : 'NO'),
            'Env OPENAI_API_KEY visible to PHP: ' . ($this->envVisible('OPENAI_API_KEY') ? 'YES' : 'NO'),
            'Env OPENAI_APIKEY visible to PHP: ' . ($this->envVisible('OPENAI_APIKEY') ? 'YES' : 'NO'),
            'Effective key resolved (non-empty): ' . (trim($this->secrets->getOpenAiApiKey()) !== '' ? 'YES' : 'NO'),
        ]);

        $io->section('Stripe');
        $io->listing([
            'Override STRIPE secret set in SiteSettings: ' . ($stripeSecretOverride !== '' ? 'YES' : 'NO'),
            'Env STRIPE_SECRET_KEY visible to PHP: ' . ($this->envVisible('STRIPE_SECRET_KEY') ? 'YES' : 'NO'),
            'Effective Stripe secret resolved (non-empty): ' . (trim($this->secrets->getStripeSecretKey()) !== '' ? 'YES' : 'NO'),
            'Override webhook secret set in SiteSettings: ' . ($stripeWebhookOverride !== '' ? 'YES' : 'NO'),
            'Env STRIPE_WEBHOOK_SECRET visible to PHP: ' . ($this->envVisible('STRIPE_WEBHOOK_SECRET') ? 'YES' : 'NO'),
            'Effective webhook secret resolved (non-empty): ' . (trim($this->secrets->getStripeWebhookSecret()) !== '' ? 'YES' : 'NO'),
        ]);

        $io->newLine();
        $io->note('If Effective key is NO while Env is YES, the runtime process likely does not receive env vars (PHP-FPM/Apache config) or cache/build steps differ from runtime.');

        return Command::SUCCESS;
    }

    private function envVisible(string $name): bool
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) && trim($value) !== '';
    }
}

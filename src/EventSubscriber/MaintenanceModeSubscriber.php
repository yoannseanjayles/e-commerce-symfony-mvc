<?php

namespace App\EventSubscriber;

use App\Repository\SiteSettingsRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SiteSettingsRepository $siteSettingsRepository,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 50],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (PHP_SAPI === 'cli') {
            return;
        }

        $settings = $this->siteSettingsRepository->findSettings();
        $maintenanceEnabled = $settings?->isMaintenanceEnabled() ?? false;

        if (!$maintenanceEnabled) {
            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $path = $request->getPathInfo() ?? '';

        $allowedPrefixes = [
            '/admin',
            '/connexion',
            '/deconnexion',
            '/stripe',
            '/assets',
            '/bundles',
            '/_profiler',
            '/_wdt',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        $allowedExact = [
            '/favicon.ico',
            '/robots.txt',
            '/sitemap.xml',
        ];

        if (in_array($path, $allowedExact, true)) {
            return;
        }

        $html = $this->twig->render('maintenance/index.html.twig');

        $response = new Response($html, Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Retry-After', '600');

        $event->setResponse($response);
    }
}

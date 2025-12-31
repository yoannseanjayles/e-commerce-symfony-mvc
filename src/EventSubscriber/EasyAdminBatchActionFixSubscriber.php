<?php

namespace App\EventSubscriber;

use App\Controller\Admin\ImagesCrudController;
use App\Entity\Images;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class EasyAdminBatchActionFixSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        
        $crudAction = $request->query->get('crudAction');
        if (!is_string($crudAction) || $crudAction === '') {
            $crudAction = $request->request->get('crudAction');
        }

        if (!is_string($crudAction) || $crudAction !== 'batchAiAssignToVariants') {
            return;
        }

        $crudControllerFqcn = $request->query->get('crudControllerFqcn');
        if (!is_string($crudControllerFqcn) || trim($crudControllerFqcn) === '') {
            $request->query->set('crudControllerFqcn', ImagesCrudController::class);
        }

        $entityFqcn = $request->query->get('entityFqcn');
        if (!is_string($entityFqcn) || trim($entityFqcn) === '') {
            $request->query->set('entityFqcn', Images::class);
        }
    }
}

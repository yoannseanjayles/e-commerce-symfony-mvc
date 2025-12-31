<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\RouterInterface;

final class AdminRoutesController
{
    public function __construct(private RouterInterface $router)
    {
    }

    #[Route('/admin/_routes', name: 'admin_routes_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $routes = $this->router->getRouteCollection()->all();

        $out = [];
        foreach ($routes as $name => $route) {
            if (!is_string($name)) {
                continue;
            }

            $out[] = [
                'name' => $name,
                'path' => $route->getPath(),
                'methods' => $route->getMethods(),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return new JsonResponse($out);
    }
}

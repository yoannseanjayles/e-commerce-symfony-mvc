<?php

namespace App\Controller\Admin;

use App\Service\BarcodeLookup\ProductSearchClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class ProductsLookupController extends AbstractController
{
    public function __construct(
        private ProductSearchClientInterface $searchClient,
    ) {
    }

    #[Route('/admin/lookup/products', name: 'admin_products_lookup_search', methods: ['GET'])]
    public function lookupSearch(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $q = (string) $request->query->get('q', '');
        $limit = (int) $request->query->get('limit', 10);

        try {
            $results = $this->searchClient->search($q, $limit);
        } catch (\Throwable) {
            // Fail closed: autocomplete is a UX helper; keep errors out of the UI.
            $results = [];
        }

        return $this->json([
            'query' => $q,
            'results' => $results,
        ]);
    }
}

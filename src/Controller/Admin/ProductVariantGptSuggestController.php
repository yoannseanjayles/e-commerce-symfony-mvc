<?php

namespace App\Controller\Admin;

use App\Service\Ai\OpenAiVariantSuggestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ProductVariantGptSuggestController extends AbstractController
{
    public function __construct(
        private readonly OpenAiVariantSuggestService $suggestService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/admin/product-variant/gpt/suggest', name: 'admin_product_variant_gpt_suggest', methods: ['POST'])]
    public function suggest(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_variant_gpt_suggest', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $fields = $payload['fields'] ?? [];
        $product = $payload['product'] ?? [];
        $options = $payload['options'] ?? [];

        if (!is_array($fields) || !is_array($product) || !is_array($options)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        $options = $this->normalizeOptions($options);

        try {
            $result = $this->suggestService->suggest($fields, $product, $options);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Suggestion failed.',
                'message' => $e->getMessage(),
            ], 500);
        }

        return $this->json($result);
    }

    #[Route('/admin/product-variant/gpt/suggest-fields', name: 'admin_product_variant_gpt_suggest_fields', methods: ['POST'])]
    public function suggestFields(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_variant_gpt_suggest_fields', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $fields = $payload['fields'] ?? [];
        $product = $payload['product'] ?? [];
        $options = $payload['options'] ?? [];

        if (!is_array($fields) || !is_array($product) || !is_array($options)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        $options = $this->normalizeOptions($options);

        try {
            $result = $this->suggestService->suggestFieldsOnly($fields, $product, $options);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Suggestion failed.',
                'message' => $e->getMessage(),
            ], 500);
        }

        return $this->json($result);
    }

    #[Route('/admin/product-variant/gpt/suggest-images', name: 'admin_product_variant_gpt_suggest_images', methods: ['POST'])]
    public function suggestImages(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_variant_gpt_suggest_images', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $fields = $payload['fields'] ?? [];
        $product = $payload['product'] ?? [];
        $options = $payload['options'] ?? [];

        if (!is_array($fields) || !is_array($product) || !is_array($options)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        $options = $this->normalizeOptions($options);

        try {
            $img = $this->suggestService->suggestSingleImageOnly($fields, $product, $options);
            $result = [
                'images' => ($img['url'] ?? null) ? [[
                    'url' => $img['url'],
                    'label' => $img['label'] ?? null,
                    'confidence' => $img['confidence'] ?? 0,
                ]] : [],
                'sources' => [],
                'notes' => (isset($img['note']) && is_string($img['note']) && trim($img['note']) !== '') ? [trim($img['note'])] : [],
            ];
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Suggestion failed.',
                'message' => $e->getMessage(),
            ], 500);
        }

        return $this->json($result);
    }

    /** @param array<string, mixed> $options */
    private function normalizeOptions(array $options): array
    {
        $aggr = $options['aggressiveness'] ?? null;
        if (is_string($aggr)) {
            $t = strtolower(trim($aggr));
            // UI parity with product panel.
            if ($t === 'light') {
                $options['aggressiveness'] = 'low';
            } elseif ($t === 'strong') {
                $options['aggressiveness'] = 'high';
            }
        }

        return $options;
    }
}

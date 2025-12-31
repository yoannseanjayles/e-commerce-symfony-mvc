<?php

namespace App\Controller\Admin;

use App\Service\Ai\OpenAiProductSuggestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ProductGptSuggestController extends AbstractController
{
    public function __construct(
        private OpenAiProductSuggestService $suggestService,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/admin/product/gpt/suggest', name: 'admin_product_gpt_suggest', methods: ['POST'])]
    public function suggest(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_gpt_suggest', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $fields = $payload['fields'] ?? [];
        $options = $payload['options'] ?? [];

        if (!is_array($fields) || !is_array($options)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        try {
            $result = $this->suggestService->suggest($fields, $options);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Suggestion failed.',
                'message' => $e->getMessage(),
            ], 500);
        }

        return $this->json($result);
    }

    #[Route('/admin/product/gpt/suggest-fields', name: 'admin_product_gpt_suggest_fields', methods: ['POST'])]
    public function suggestFields(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_gpt_suggest_fields', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $fields = $payload['fields'] ?? [];
        $options = $payload['options'] ?? [];

        if (!is_array($fields) || !is_array($options)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        try {
            $result = $this->suggestService->suggestFieldsOnly($fields, $options);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Suggestion failed.',
                'message' => $e->getMessage(),
            ], 500);
        }

        return $this->json($result);
    }

    #[Route('/admin/product/gpt/suggest-images', name: 'admin_product_gpt_suggest_images', methods: ['POST'])]
    public function suggestImages(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_gpt_suggest_images', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $fields = $payload['fields'] ?? [];
        $options = $payload['options'] ?? [];

        if (!is_array($fields) || !is_array($options)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        try {
            $img = $this->suggestService->suggestSingleImageOnly($fields, $options);
            $result = [
                'images' => ($img['url'] ?? null) ? [[
                    'url' => $img['url'],
                    'label' => $img['label'] ?? null,
                    'color' => null,
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

    #[Route('/admin/product/gpt/suggest-variants', name: 'admin_product_gpt_suggest_variants', methods: ['POST'])]
    public function suggestVariants(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON payload.'], 400);
        }

        $csrfToken = (string) ($payload['_csrf'] ?? '');
        $token = new CsrfToken('admin_product_gpt_suggest_variants', $csrfToken);
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Invalid CSRF token.'], 403);
        }

        $fields = $payload['fields'] ?? [];
        $options = $payload['options'] ?? [];

        if (!is_array($fields) || !is_array($options)) {
            return $this->json(['error' => 'Invalid payload shape.'], 400);
        }

        try {
            $result = $this->suggestService->suggestVariantsOnly($fields, $options);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Suggestion failed.',
                'message' => $e->getMessage(),
            ], 500);
        }

        return $this->json($result);
    }
}

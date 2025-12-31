<?php

namespace App\Controller\Admin;

use App\Entity\ProductVariant;
use App\Entity\Products;
use App\Entity\Images;
use App\Service\Ai\OpenAiProductSuggestService;
use App\Service\Ai\OpenAiVariantAutofillService;
use App\Service\Catalog\ColorCatalog;
use App\Repository\OrdersDetailsRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\String\Slugger\SluggerInterface;

final class ProductVariantCrudController extends AbstractCrudController
{
    public function __construct(
        private SluggerInterface $slugger,
        private ColorCatalog $colorCatalog,
        private OrdersDetailsRepository $ordersDetailsRepository,
        private OpenAiVariantAutofillService $variantAutofillService,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return ProductVariant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Variante')
            ->setEntityLabelInPlural('Variantes')
            ->setDefaultSort(['id' => 'DESC'])
            ->overrideTemplates([
                'crud/new' => 'admin/product_variants/crud_new.html.twig',
                'crud/edit' => 'admin/product_variants/crud_edit.html.twig',
            ]);
    }

    public function configureActions(Actions $actions): Actions
    {
        $canDelete = function ($entity): bool {
            if (!$entity instanceof ProductVariant) {
                return false;
            }

            return $this->ordersDetailsRepository->count(['productVariant' => $entity]) === 0;
        };

        $gptAddColorVariants = Action::new('gptAddColorVariants', '🎨 GPT: Ajouter variantes couleurs', 'fas fa-palette')
            ->linkToCrudAction('gptAddColorVariantsForm')
            ->displayAsLink()
            ->displayIf(static function ($entity): bool {
                return $entity instanceof ProductVariant && $entity->getProducts() !== null;
            });

        return $actions
            ->add(Crud::PAGE_INDEX, $gptAddColorVariants)
            ->add(Crud::PAGE_EDIT, $gptAddColorVariants)
            ->add(Crud::PAGE_DETAIL, $gptAddColorVariants)
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action->displayIf($canDelete))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn (Action $action) => $action->displayIf($canDelete))
            ->add(Crud::PAGE_EDIT, Action::DELETE)
            ->update(Crud::PAGE_EDIT, Action::DELETE, static fn (Action $action) => $action->displayIf($canDelete));
    }

    public function gptAddColorVariantsForm(AdminContext $context, Request $request, OpenAiProductSuggestService $suggestService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $variant = $this->getVariantFromContext($context);
        if (!$variant instanceof ProductVariant) {
            $this->addFlash('danger', 'Variante introuvable.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $product = $variant->getProducts();
        if (!$product instanceof Products || $product->getId() === null) {
            $this->addFlash('warning', 'Cette variante n\'est associée à aucun produit.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $backUrl = (string) ($context->getReferrer() ?? '/admin');

        $existingColors = [];
        $existingVariants = [];
        foreach ($product->getVariants() as $existingVariant) {
            if (!$existingVariant instanceof ProductVariant) {
                continue;
            }
            if ($existingVariant->getName() === 'Variante par défaut') {
                continue;
            }
            $existingVariants[] = $existingVariant;
            $label = $this->colorCatalog->labelFor($existingVariant->getColor());
            if ($label === null) {
                continue;
            }
            $existingColors[mb_strtolower($label)] = true;
        }

        $basePrice = 0;
        $baseStock = 0;
        foreach ($product->getVariants() as $existingVariant) {
            if ($existingVariant instanceof ProductVariant) {
                $basePrice = (int) ($existingVariant->getPrice() ?? 0);
                $baseStock = (int) ($existingVariant->getStock() ?? 0);
                break;
            }
        }

        $suggested = [];
        $suggestedPayload = null;

        if ($request->isMethod('POST')) {
            $csrf = (string) $request->request->get('_csrf', '');
            if (!$this->isCsrfTokenValid('admin_variant_gpt_add_colors', $csrf)) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');
                return $this->redirect($backUrl);
            }

            $webSearch = $this->readBool($request, 'webSearch', false);
            $aggressiveness = (string) $request->request->get('aggressiveness', 'low');
            if (!in_array($aggressiveness, ['low', 'medium', 'high'], true)) {
                $aggressiveness = 'low';
            }

            $backUrlFromForm = $request->request->get('backUrl');
            if (is_string($backUrlFromForm) && trim($backUrlFromForm) !== '') {
                $backUrl = $backUrlFromForm;
            }

            $mode = (string) $request->request->get('mode', 'search');

            if ($mode === 'import') {
                $payload = (string) $request->request->get('suggestedPayload', '');
                $decoded = $payload !== '' ? base64_decode($payload, true) : false;
                $data = is_string($decoded) ? json_decode($decoded, true) : null;
                if (!is_array($data)) {
                    $this->addFlash('warning', 'Suggestions introuvables: relancez la génération.');
                    return $this->redirect($context->getRequest()->getUri());
                }

                $selectedIndexes = $request->request->all('selected');
                if (!is_array($selectedIndexes) || $selectedIndexes === []) {
                    $this->addFlash('warning', 'Aucune variante sélectionnée.');
                    return $this->redirect($context->getRequest()->getUri());
                }

                $created = 0;
                $skipped = 0;

                foreach ($selectedIndexes as $idxRaw) {
                    $idx = (int) $idxRaw;
                    if (!isset($data[$idx]) || !is_array($data[$idx])) {
                        $skipped++;
                        continue;
                    }
                    $s = $data[$idx];

                    $colorLabel = isset($s['colorLabel']) && is_string($s['colorLabel']) ? trim($s['colorLabel']) : '';
                    if ($colorLabel === '') {
                        $skipped++;
                        continue;
                    }

                    $colorKey = mb_strtolower($colorLabel);
                    if (isset($existingColors[$colorKey])) {
                        $skipped++;
                        continue;
                    }
                    $existingColors[$colorKey] = true;

                    $newVariant = new ProductVariant();
                    $name = isset($s['name']) && is_string($s['name']) ? trim($s['name']) : '';
                    if ($name === '') {
                        $productName = (string) ($product->getName() ?? 'Produit');
                        $name = $productName . ' - ' . $colorLabel;
                    }

                    $newVariant->setName($name);
                    $newVariant->setColor($colorLabel);

                    $colorCode = isset($s['colorCode']) && is_string($s['colorCode']) ? $s['colorCode'] : $colorLabel;
                    $newVariant->setColorCode($this->colorCatalog->cssValueFor($colorCode));
                    $newVariant->setPrice($basePrice);
                    $newVariant->setStock(max(0, $baseStock));

                    $product->addVariant($newVariant);
                    $this->ensureSlug($newVariant);
                    $this->entityManager->persist($newVariant);
                    $created++;
                }

                if ($created > 0) {
                    $this->entityManager->flush();
                    $this->addFlash('success', sprintf('Variantes créées: %d (ignorées: %d).', $created, $skipped));
                    return $this->redirect($backUrl);
                }

                $this->addFlash('info', sprintf('Aucune variante créée (ignorées: %d).', $skipped));
                return $this->redirect($context->getRequest()->getUri());
            }

            // mode === 'search'
            $fields = [
                'name' => (string) ($product->getName() ?? ''),
                'description' => (string) ($product->getDescription() ?? ''),
                'brand' => $product->getBrand(),
                'productType' => $product->getProductType(),
                'gender' => $product->getGender(),
                'frameShape' => $product->getFrameShape(),
                'frameMaterial' => $product->getFrameMaterial(),
                'frameStyle' => $product->getFrameStyle(),
                'uvProtection' => $product->getUvProtection(),
                'polarized' => $product->isPolarized(),
                'prescriptionAvailable' => $product->isPrescriptionAvailable(),
                'colorOptions' => array_keys($this->colorCatalog->choices()),
            ];

            try {
                $ai = $suggestService->suggestVariantsOnly($fields, [
                    'aggressiveness' => $aggressiveness,
                    'webSearch' => $webSearch,
                ]);
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'GPT a échoué: ' . $e->getMessage());
                return $this->redirect($context->getReferrer() ?? '/admin');
            }

            $raw = $ai['variants'] ?? [];
            if (!is_array($raw) || $raw === []) {
                $this->addFlash('info', 'GPT n\'a proposé aucune variante.');
                return $this->redirect($context->getRequest()->getUri());
            }

            $seen = $existingColors;
            $out = [];
            foreach ($raw as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $colorLabel = $this->resolveSuggestedColorLabel($item);
                $colorKey = mb_strtolower($colorLabel);
                $isDuplicate = isset($seen[$colorKey]);
                if (!$isDuplicate) {
                    $seen[$colorKey] = true;
                }

                $name = isset($item['name']) && is_string($item['name']) ? trim($item['name']) : '';
                if ($name === '') {
                    $productName = (string) ($product->getName() ?? 'Produit');
                    $name = $productName . ' - ' . $colorLabel;
                }

                $out[] = [
                    'name' => $name,
                    'colorLabel' => $colorLabel,
                    'colorCode' => (string) ($item['colorCode'] ?? $colorLabel),
                    'colorCss' => (string) ($this->colorCatalog->cssValueFor($item['colorCode'] ?? $colorLabel) ?? ''),
                    'duplicate' => $isDuplicate,
                ];

                if (count($out) >= 24) {
                    break;
                }
            }

            $suggested = $out;
            $suggestedPayload = base64_encode((string) json_encode($suggested));
        }

        $webSearch = $this->readBool($request, 'webSearch', false);
        $aggressiveness = (string) $request->query->get('aggressiveness', 'low');
        if (!in_array($aggressiveness, ['low', 'medium', 'high'], true)) {
            $aggressiveness = 'low';
        }

        return $this->render('admin/product_variants/gpt_add_color_variants.html.twig', [
            'variant' => $variant,
            'product' => $product,
            'existingVariants' => $existingVariants,
            'aggressiveness' => $aggressiveness,
            'webSearch' => $webSearch,
            'backUrl' => $backUrl,
            'suggested' => $suggested,
            'suggestedPayload' => $suggestedPayload,
        ]);
    }

    private function readBool(Request $request, string $key, bool $default = false): bool
    {
        $raw = $request->request->has($key) ? $request->request->get($key) : $request->query->get($key);
        if ($raw === null) {
            return $default;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return ((int) $raw) !== 0;
        }
        if (is_string($raw)) {
            $t = strtolower(trim($raw));
            if ($t === '' || $t === '0' || $t === 'false' || $t === 'no' || $t === 'non') {
                return false;
            }
            if ($t === '1' || $t === 'true' || $t === 'yes' || $t === 'oui') {
                return true;
            }
        }
        return (bool) $raw;
    }

    public function gptAddColorVariants(AdminContext $context, OpenAiProductSuggestService $suggestService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $request = $context->getRequest();
        $webSearch = (bool) $request->query->get('webSearch', false);
        $aggressiveness = (string) $request->query->get('aggressiveness', 'low');
        if (!in_array($aggressiveness, ['low', 'medium', 'high'], true)) {
            $aggressiveness = 'low';
        }

        $variant = $this->getVariantFromContext($context);
        if (!$variant instanceof ProductVariant) {
            $this->addFlash('danger', 'Variante introuvable.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $product = $variant->getProducts();
        if ($product === null || $product->getId() === null) {
            $this->addFlash('warning', 'Cette variante n\'est associée à aucun produit.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $existingColors = [];
        foreach ($product->getVariants() as $existingVariant) {
            if (!$existingVariant instanceof ProductVariant) {
                continue;
            }
            $label = $this->colorCatalog->labelFor($existingVariant->getColor());
            if ($label === null) {
                continue;
            }
            $existingColors[strtolower($label)] = true;
        }

        $basePrice = 0;
        $baseStock = 0;
        foreach ($product->getVariants() as $existingVariant) {
            if ($existingVariant instanceof ProductVariant) {
                $basePrice = (int) ($existingVariant->getPrice() ?? 0);
                $baseStock = (int) ($existingVariant->getStock() ?? 0);
                break;
            }
        }

        $fields = [
            'name' => (string) ($product->getName() ?? ''),
            'description' => (string) ($product->getDescription() ?? ''),
            'brand' => $product->getBrand(),
            'productType' => $product->getProductType(),
            'gender' => $product->getGender(),
            'frameShape' => $product->getFrameShape(),
            'frameMaterial' => $product->getFrameMaterial(),
            'frameStyle' => $product->getFrameStyle(),
            'uvProtection' => $product->getUvProtection(),
            'polarized' => $product->isPolarized(),
            'prescriptionAvailable' => $product->isPrescriptionAvailable(),
            'colorOptions' => array_keys($this->colorCatalog->choices()),
        ];

        $result = $this->runAddColorVariants($product, $suggestService, $aggressiveness, $webSearch, $context);
        if ($result !== null) {
            return $result;
        }

        return $this->redirect($context->getReferrer() ?? '/admin');
    }

    private function runAddColorVariants(Products $product, OpenAiProductSuggestService $suggestService, string $aggressiveness, bool $webSearch, AdminContext $context): ?Response
    {
        $existingColors = [];
        foreach ($product->getVariants() as $existingVariant) {
            if (!$existingVariant instanceof ProductVariant) {
                continue;
            }
            $label = $this->colorCatalog->labelFor($existingVariant->getColor());
            if ($label === null) {
                continue;
            }
            $existingColors[strtolower($label)] = true;
        }

        $basePrice = 0;
        $baseStock = 0;
        foreach ($product->getVariants() as $existingVariant) {
            if ($existingVariant instanceof ProductVariant) {
                $basePrice = (int) ($existingVariant->getPrice() ?? 0);
                $baseStock = (int) ($existingVariant->getStock() ?? 0);
                break;
            }
        }

        $fields = [
            'name' => (string) ($product->getName() ?? ''),
            'description' => (string) ($product->getDescription() ?? ''),
            'brand' => $product->getBrand(),
            'productType' => $product->getProductType(),
            'gender' => $product->getGender(),
            'frameShape' => $product->getFrameShape(),
            'frameMaterial' => $product->getFrameMaterial(),
            'frameStyle' => $product->getFrameStyle(),
            'uvProtection' => $product->getUvProtection(),
            'polarized' => $product->isPolarized(),
            'prescriptionAvailable' => $product->isPrescriptionAvailable(),
            'colorOptions' => array_keys($this->colorCatalog->choices()),
        ];

        try {
            $result = $suggestService->suggestVariantsOnly($fields, [
                'aggressiveness' => $aggressiveness,
                'webSearch' => $webSearch,
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'GPT a échoué: ' . $e->getMessage());
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $suggestedVariants = $result['variants'] ?? [];
        if (!is_array($suggestedVariants) || $suggestedVariants === []) {
            $this->addFlash('info', 'GPT n\'a proposé aucune variante.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $created = 0;
        $skipped = 0;
        $skippedReasons = [
            'invalid' => 0,
            'duplicate' => 0,
        ];

        foreach ($suggestedVariants as $suggested) {
            if (!is_array($suggested)) {
                $skipped++;
                $skippedReasons['invalid']++;
                continue;
            }

            $colorLabel = $this->resolveSuggestedColorLabel($suggested);
            $colorKey = strtolower($colorLabel);
            if (isset($existingColors[$colorKey])) {
                $skipped++;
                $skippedReasons['duplicate']++;
                continue;
            }

            $existingColors[$colorKey] = true;

            $newVariant = new ProductVariant();
            $name = $suggested['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                $productName = (string) ($product->getName() ?? 'Produit');
                $name = $productName . ' - ' . $colorLabel;
            }

            $newVariant->setName(trim($name));
            $newVariant->setColor($colorLabel);
            $newVariant->setColorCode($this->colorCatalog->cssValueFor($suggested['colorCode'] ?? $colorLabel));
            $newVariant->setPrice($basePrice);
            $newVariant->setStock(max(0, $baseStock));

            $product->addVariant($newVariant);
            $this->ensureSlug($newVariant);
            $this->entityManager->persist($newVariant);
            $created++;
        }

        if ($created > 0) {
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Variantes couleur ajoutées: %d (ignorées: %d).', $created, $skipped));
        } else {
            $this->addFlash('info', sprintf('Aucune variante créée (ignorées: %d).', $skipped));
        }

        if ($skipped > 0) {
            $details = [];
            if (($skippedReasons['duplicate'] ?? 0) > 0) {
                $details[] = sprintf('déjà existantes: %d', (int) $skippedReasons['duplicate']);
            }
            if (($skippedReasons['invalid'] ?? 0) > 0) {
                $details[] = sprintf('données invalides: %d', (int) $skippedReasons['invalid']);
            }
            if ($details !== []) {
                $this->addFlash('warning', 'Détail ignorées: ' . implode(' | ', $details) . '.');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $suggested */
    private function resolveSuggestedColorLabel(array $suggested): string
    {
        $rawColor = isset($suggested['color']) && is_string($suggested['color']) ? trim($suggested['color']) : '';
        if ($rawColor !== '') {
            $label = $this->colorCatalog->labelFor($rawColor);
            if ($label !== null && trim($label) !== '') {
                return $label;
            }
        }

        $rawColorCode = isset($suggested['colorCode']) && is_string($suggested['colorCode']) ? trim($suggested['colorCode']) : '';
        if ($rawColorCode !== '') {
            $label = $this->colorCatalog->labelFor($rawColorCode);
            if ($label !== null && trim($label) !== '') {
                return $label;
            }
        }

        $name = isset($suggested['name']) && is_string($suggested['name']) ? trim($suggested['name']) : '';
        if ($name !== '') {
            $label = $this->inferColorLabelFromName($name);
            if ($label !== null && trim($label) !== '') {
                return $label;
            }

            $fallback = $this->extractColorSuffixFromName($name);
            if ($fallback !== null && trim($fallback) !== '') {
                return $fallback;
            }

            // Last resort: keep the name itself as a unique color label so we don't silently drop the suggestion.
            return $name;
        }

        // Absolute last resort.
        return 'Couleur';
    }

    private function inferColorLabelFromName(string $name): ?string
    {
        $haystack = mb_strtolower($name);
        foreach (array_keys($this->colorCatalog->choices()) as $label) {
            $needle = mb_strtolower((string) $label);
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return (string) $label;
            }
        }
        return null;
    }

    private function extractColorSuffixFromName(string $name): ?string
    {
        // Common GPT naming pattern: "<Product> - <Color>".
        $parts = preg_split('/\s*-\s*/', $name);
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }
        $last = trim((string) end($parts));
        if ($last === '' || mb_strlen($last) > 60) {
            return null;
        }
        return $last;
    }

    public function gptFill(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $request = $context->getRequest();
        $webSearch = (bool) $request->query->get('webSearch', false);
        $aggressiveness = (string) $request->query->get('aggressiveness', 'low');
        if (!in_array($aggressiveness, ['low', 'medium', 'high'], true)) {
            $aggressiveness = 'low';
        }

        $entity = $this->getVariantFromContext($context);
        if (!$entity instanceof ProductVariant) {
            $this->addFlash('danger', 'Variante introuvable.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        try {
            $allowedColors = array_keys($this->colorCatalog->choices());
            $suggested = $this->variantAutofillService->suggest($entity, $allowedColors, [
                'aggressiveness' => $aggressiveness,
                'webSearch' => $webSearch,
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'GPT a échoué: ' . $e->getMessage());
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $changed = 0;

        $applyIfEmpty = static function (?string $current, ?string $value): ?string {
            if (is_string($current) && trim($current) !== '') {
                return $current;
            }
            return is_string($value) && trim($value) !== '' ? trim($value) : $current;
        };

        $newName = $applyIfEmpty($entity->getName(), $suggested['name'] ?? null);
        if ($newName !== $entity->getName() && is_string($newName) && trim($newName) !== '') {
            $entity->setName($newName);
            $changed++;
        }

        $newColor = $applyIfEmpty($entity->getColor(), $suggested['color'] ?? null);
        if ($newColor !== $entity->getColor()) {
            $entity->setColor($newColor);
            $changed++;
        }

        $newColorCode = $applyIfEmpty($entity->getColorCode(), $suggested['colorCode'] ?? null);
        if ($newColorCode !== $entity->getColorCode()) {
            $entity->setColorCode($newColorCode);
            $changed++;
        }

        $newSize = $applyIfEmpty($entity->getSize(), $suggested['size'] ?? null);
        if ($newSize !== $entity->getSize()) {
            $entity->setSize($newSize);
            $changed++;
        }

        $applyIntIfNull = static function (?int $current, mixed $value): ?int {
            if ($current !== null) {
                return $current;
            }
            return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
        };

        $lw = $applyIntIfNull($entity->getLensWidthMm(), $suggested['lensWidthMm'] ?? null);
        if ($lw !== $entity->getLensWidthMm()) {
            $entity->setLensWidthMm($lw);
            $changed++;
        }
        $bw = $applyIntIfNull($entity->getBridgeWidthMm(), $suggested['bridgeWidthMm'] ?? null);
        if ($bw !== $entity->getBridgeWidthMm()) {
            $entity->setBridgeWidthMm($bw);
            $changed++;
        }
        $tl = $applyIntIfNull($entity->getTempleLengthMm(), $suggested['templeLengthMm'] ?? null);
        if ($tl !== $entity->getTempleLengthMm()) {
            $entity->setTempleLengthMm($tl);
            $changed++;
        }
        $lh = $applyIntIfNull($entity->getLensHeightMm(), $suggested['lensHeightMm'] ?? null);
        if ($lh !== $entity->getLensHeightMm()) {
            $entity->setLensHeightMm($lh);
            $changed++;
        }

        if ($changed > 0) {
            $this->ensureSlug($entity);
            $this->entityManager->persist($entity);
            $this->entityManager->flush();
            $this->addFlash('success', 'Variante complétée (champs vides): ' . $changed . ' champ(s).');
        } else {
            $this->addFlash('info', 'Aucun champ vide complété (rien à faire).');
        }

        return $this->redirect($context->getReferrer() ?? '/admin');
    }

    private function getVariantFromContext(AdminContext $context): ?ProductVariant
    {
        $request = $context->getRequest();
        $rawId = $request->query->get('entityId') ?? $request->query->get('id');
        if (!is_scalar($rawId)) {
            return null;
        }

        $id = (string) $rawId;
        if ($id === '' || !ctype_digit($id)) {
            return null;
        }

        return $this->entityManager->getRepository(ProductVariant::class)->find((int) $id);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        if (!str_starts_with($pageName, 'embedded')) {
            yield AssociationField::new('products', 'Produit');
        }
        yield TextField::new('name', 'Nom');
        yield TextField::new('slug', 'Slug')->hideOnIndex();
        yield TextField::new('sku', 'SKU')->setRequired(false);
        yield TextField::new('barcode', 'Code-barres')->setRequired(false);
        yield ChoiceField::new('color', 'Couleur')
            ->setRequired(false)
            ->setChoices($this->colorCatalog->choices())
            ->renderExpanded(false);
        yield TextField::new('colorCode', 'Code couleur')->setRequired(false);
        yield TextField::new('size', 'Taille(s)')
            ->setRequired(false)
            ->setHelp('Optionnel. Une ou plusieurs tailles (ex: "S", ou "S, M, L"). Un seul stock est géré pour la variante.');

        yield AssociationField::new('primaryImage', 'Image (variante)')
            ->setRequired(false)
            ->setTemplatePath('admin/field/image_thumbnail.html.twig')
            ->setHelp('Optionnel. Associe une image précise à cette variante (ex: la photo de la couleur).');

        yield IntegerField::new('lensWidthMm', 'Largeur verre (mm)')->setRequired(false);
        yield IntegerField::new('bridgeWidthMm', 'Pont (mm)')->setRequired(false);
        yield IntegerField::new('templeLengthMm', 'Branche (mm)')->setRequired(false);

        yield MoneyField::new('price', 'Prix')
            ->setCurrency('EUR')
            ->setStoredAsCents()
            ->setRequired(false);

        yield IntegerField::new('stock', 'Stock')->setRequired(false);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            $this->ensureSlug($entityInstance);

            $img = $entityInstance->getPrimaryImage();
            if ($img !== null && $img->getProducts() !== null && $entityInstance->getProducts() !== null) {
                if ($img->getProducts()->getId() !== $entityInstance->getProducts()->getId()) {
                    $entityInstance->setPrimaryImage(null);
                    $this->addFlash('warning', 'Image de variante ignorée: elle ne correspond pas au même produit.');
                }
            }

            $request = $this->requestStack->getCurrentRequest();
            $token = $request?->request->get('gpt_staged_images_token');
            if (is_string($token)) {
                $token = trim($token);
            } else {
                $token = '';
            }

            $product = $entityInstance->getProducts();
            if ($token !== '' && $request && $request->hasSession() && $product instanceof Products) {
                $session = $request->getSession();
                $key = 'gpt_staged_images:' . $token;
                $rows = $session->get($key);

                if (is_array($rows)) {
                    $added = 0;
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $fileName = isset($row['fileName']) && is_string($row['fileName']) ? trim($row['fileName']) : '';
                        $sourceUrl = isset($row['sourceUrl']) && is_string($row['sourceUrl']) ? trim($row['sourceUrl']) : null;
                        $colorTag = isset($row['colorTag']) && is_string($row['colorTag']) ? trim($row['colorTag']) : null;
                        if ($fileName === '') {
                            continue;
                        }

                        $image = new Images();
                        $image->setName($fileName);
                        $image->setSourceUrl($sourceUrl !== '' ? $sourceUrl : null);
                        $image->setColorTag($colorTag !== '' ? $colorTag : null);
                        $product->addImage($image);
                        $entityManager->persist($image);
                        $added++;

                        if ($added >= self::MAX_GPT_STAGED_IMAGES_ATTACH) {
                            break;
                        }
                    }

                    if ($added > 0) {
                        $this->addFlash('success', 'Images GPT ajoutées automatiquement: ' . $added . '.');
                    }
                }

                $session->remove($key);
            }
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    private const MAX_GPT_STAGED_IMAGES_ATTACH = 30;

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof ProductVariant) {
            $this->ensureSlug($entityInstance);

            $img = $entityInstance->getPrimaryImage();
            if ($img !== null && $img->getProducts() !== null && $entityInstance->getProducts() !== null) {
                if ($img->getProducts()->getId() !== $entityInstance->getProducts()->getId()) {
                    $entityInstance->setPrimaryImage(null);
                    $this->addFlash('warning', 'Image de variante ignorée: elle ne correspond pas au même produit.');
                }
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function ensureSlug(ProductVariant $variant): void
    {
        $slug = $variant->getSlug();
        if ($slug !== null && trim($slug) !== '') {
            return;
        }

        $name = $variant->getName();
        if ($name === null || trim($name) === '') {
            return;
        }

        $variant->setSlug($this->slugger->slug($name)->lower()->toString());
    }
}

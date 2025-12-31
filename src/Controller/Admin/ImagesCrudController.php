<?php

namespace App\Controller\Admin;

use App\Entity\Images;
use App\Entity\ProductVariant;
use App\Entity\Products;
use App\Repository\ProductVariantRepository;
use App\Repository\ProductsRepository;
use App\Service\Ai\AiProductImageImportService;
use App\Service\Ai\OpenAiProductSuggestService;
use App\Service\Ai\OpenAiVariantSuggestService;
use App\Service\Ai\OpenAiImageColorTagService;
use App\Service\Catalog\ColorCatalog;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\Image;

class ImagesCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly OpenAiImageColorTagService $imageColorTagService,
        private readonly ColorCatalog $colorCatalog,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Images::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Image')
            ->setEntityLabelInPlural('Images')
            ->setDefaultSort(['id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $gptColorTag = Action::new('gptColorTag', '✨ GPT: Proposer colorTag', 'fas fa-magic')
            ->linkToCrudAction('gptColorTag');

        $assignToVariant = Action::new('assignToVariant', '🔗 Associer à une variante', 'fas fa-link')
            ->linkToCrudAction('assignToVariantForm');

        $gptFindImages = Action::new('gptFindImages', '🖼️ IA: Chercher des images', 'fas fa-image')
            ->linkToCrudAction('gptFindImagesForm')
            ->createAsGlobalAction();

        $aiAssignToVariants = Action::new('aiAssignToVariants', '🤖 IA: Attribuer aux variantes', 'fas fa-random')
            ->linkToCrudAction('batchAiAssignToVariants')
            ->createAsBatchAction();

        return $actions
            ->add(Crud::PAGE_INDEX, $gptFindImages)
            ->addBatchAction($aiAssignToVariants)
            ->add(Crud::PAGE_EDIT, $gptColorTag)
            ->add(Crud::PAGE_EDIT, $assignToVariant)
            ->add(Crud::PAGE_DETAIL, $gptColorTag)
            ->add(Crud::PAGE_DETAIL, $assignToVariant);
    }

    public function batchAiAssignToVariants(
        AdminContext $context,
        BatchActionDto $batchActionDto,
        ProductVariantRepository $variantRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('ea-batch-action-' . $batchActionDto->getName(), $batchActionDto->getCsrfToken())) {
            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        if ($batchActionDto->getEntityFqcn() !== Images::class) {
            $this->addFlash('danger', 'Action batch invalide.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        $imageRepo = $this->entityManager->getRepository(Images::class);
        $images = [];
        foreach ($batchActionDto->getEntityIds() as $id) {
            $img = $imageRepo->find($id);
            if ($img instanceof Images && $img->getId() !== null) {
                $images[] = $img;
            }
        }

        if ($images === []) {
            $this->addFlash('warning', 'Aucune image sélectionnée.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        $product = $images[0]->getProducts();
        if (!$product instanceof Products || $product->getId() === null) {
            $this->addFlash('danger', 'Produit introuvable pour la sélection.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        foreach ($images as $img) {
            if ($img->getProducts() === null || $img->getProducts()->getId() !== $product->getId()) {
                $this->addFlash('danger', 'Veuillez sélectionner des images d\'un seul produit à la fois.');
                return $this->redirect($batchActionDto->getReferrerUrl());
            }
        }

        $variantsAll = $variantRepository->findBy(['products' => $product], ['id' => 'DESC'], 300);
        $variants = [];
        foreach ($variantsAll as $v) {
            if (!$v instanceof ProductVariant) {
                continue;
            }
            if ($v->getName() === 'Variante par défaut') {
                continue;
            }
            $variants[] = $v;
        }

        if ($variants === []) {
            $this->addFlash('warning', 'Aucune variante disponible pour ce produit.');
            return $this->redirect($batchActionDto->getReferrerUrl());
        }

        $variantsByColor = [];
        foreach ($variants as $v) {
            $label = $this->colorCatalog->labelFor($v->getColor());
            if (!is_string($label) || trim($label) === '') {
                continue;
            }
            $key = mb_strtolower(trim($label));
            if (!isset($variantsByColor[$key])) {
                $variantsByColor[$key] = [];
            }
            $variantsByColor[$key][] = $v;
        }

        $allowedColors = array_keys($this->colorCatalog->choices());

        $tagged = 0;
        $assigned = 0;
        $skippedNoMatch = 0;
        $skippedAlreadySet = 0;
        $errors = 0;

        foreach ($images as $img) {
            $colorTag = $img->getColorTag();

            if (!is_string($colorTag) || trim($colorTag) === '') {
                try {
                    $result = $this->imageColorTagService->suggestColorTag($img, $allowedColors);
                    $suggestedTag = $result['colorTag'] ?? null;
                    if (is_string($suggestedTag) && trim($suggestedTag) !== '') {
                        $img->setColorTag($suggestedTag);
                        $this->entityManager->persist($img);
                        $colorTag = $suggestedTag;
                        $tagged++;
                    }
                } catch (\Throwable) {
                    $errors++;
                    continue;
                }
            }

            $label = $this->colorCatalog->labelFor($colorTag);
            if (!is_string($label) || trim($label) === '') {
                $skippedNoMatch++;
                continue;
            }

            $key = mb_strtolower(trim($label));
            if (!isset($variantsByColor[$key]) || $variantsByColor[$key] === []) {
                $skippedNoMatch++;
                continue;
            }

            $matched = null;
            foreach ($variantsByColor[$key] as $v) {
                $primary = $v->getPrimaryImage();
                if ($primary instanceof Images && $primary->getId() === $img->getId()) {
                    $matched = null;
                    break;
                }
                if ($primary === null) {
                    $matched = $v;
                    break;
                }
            }

            if (!$matched instanceof ProductVariant) {
                $skippedAlreadySet++;
                continue;
            }

            $matched->setPrimaryImage($img);
            $this->entityManager->persist($matched);
            $assigned++;
        }

        if ($tagged > 0 || $assigned > 0) {
            $this->entityManager->flush();
        }

        $this->addFlash('success', sprintf('IA: %d image(s) analysée(s), %d taggée(s), %d assignée(s) aux variantes.', count($images), $tagged, $assigned));
        if ($skippedNoMatch > 0) {
            $this->addFlash('warning', sprintf('Aucune variante correspondante (couleur): %d image(s).', $skippedNoMatch));
        }
        if ($skippedAlreadySet > 0) {
            $this->addFlash('info', sprintf('Variantes déjà pourvues (non remplacées): %d image(s).', $skippedAlreadySet));
        }
        if ($errors > 0) {
            $this->addFlash('danger', sprintf('Erreurs IA (colorTag) sur %d image(s).', $errors));
        }

        return $this->redirect($batchActionDto->getReferrerUrl());
    }

    public function gptFindImagesForm(
        AdminContext $context,
        Request $request,
        ProductsRepository $productsRepository,
        ProductVariantRepository $variantRepository,
        OpenAiProductSuggestService $productSuggestService,
        OpenAiVariantSuggestService $variantSuggestService,
        AiProductImageImportService $imageImportService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $backUrl = (string) ($context->getReferrer() ?? '/admin');
        $backUrlFromForm = $request->request->get('backUrl');
        if (is_string($backUrlFromForm) && trim($backUrlFromForm) !== '') {
            $backUrl = $backUrlFromForm;
        }

        $aggressiveness = (string) ($request->request->get('aggressiveness', $request->query->get('aggressiveness', 'medium')));
        if (!in_array($aggressiveness, ['low', 'medium', 'high'], true)) {
            $aggressiveness = 'medium';
        }
        $webSearch = $this->readBool($request, 'webSearch', false);

        $productId = (int) ($request->request->get('productId', $request->query->get('productId', 0)));
        $variantId = (int) ($request->request->get('variantId', $request->query->get('variantId', 0)));

        $selectedProduct = $productId > 0 ? $productsRepository->find($productId) : null;
        $selectedVariant = $variantId > 0 ? $variantRepository->find($variantId) : null;

        if ($selectedVariant instanceof ProductVariant && $selectedProduct instanceof Products) {
            if ($selectedVariant->getProducts() === null || $selectedVariant->getProducts()->getId() !== $selectedProduct->getId()) {
                $selectedVariant = null;
            }
        }

        // Lists for selects (kept small).
        $products = $productsRepository->createQueryBuilder('p')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $variants = [];
        if ($selectedProduct instanceof Products) {
            $variants = $variantRepository->findBy(['products' => $selectedProduct], ['id' => 'DESC'], 300);
        }

        $suggestedImages = [];
        $sources = [];
        $notes = [];
        $previewStagingErrors = [];
        $maxImagesRaw = $request->request->get('maxImages', $request->query->get('maxImages', 10));
        $maxImages = is_numeric($maxImagesRaw) ? (int) $maxImagesRaw : 10;
        $maxImages = max(1, min(30, $maxImages));

        if ($request->isMethod('POST')) {
            $mode = (string) $request->request->get('mode', 'search');

            if ($mode === 'import') {
                $csrf = (string) $request->request->get('_csrf', '');
                if (!$this->isCsrfTokenValid('admin_images_gpt_import_images', $csrf)) {
                    $this->addFlash('danger', 'Jeton CSRF invalide.');
                    return $this->redirect($backUrl);
                }

                if (!$selectedProduct instanceof Products || $selectedProduct->getId() === null) {
                    $this->addFlash('danger', 'Produit introuvable.');
                    return $this->redirect($backUrl);
                }

                $selectedUrls = $request->request->all('selectedUrls');
                if (!is_array($selectedUrls)) {
                    $selectedUrls = [];
                }

                $colorTag = null;
                if ($selectedVariant instanceof ProductVariant) {
                    $colorTag = $selectedVariant->getColor();
                }

                $items = [];
                foreach ($selectedUrls as $u) {
                    if (!is_string($u)) {
                        continue;
                    }
                    $url = trim($u);
                    if ($url === '') {
                        continue;
                    }
                    $items[] = ['url' => $url, 'colorTag' => $colorTag];
                    if (count($items) >= $maxImages) {
                        break;
                    }
                }

                if (count($items) === 0) {
                    $this->addFlash('warning', 'Aucune image sélectionnée.');
                    return $this->redirect($context->getRequest()->getUri());
                }

                try {
                    $result = $imageImportService->addImagesFromItems($selectedProduct, $items);
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'Import impossible: ' . $e->getMessage());
                    return $this->redirect($context->getRequest()->getUri());
                }

                $added = (int) ($result['added'] ?? 0);
                $skipped = (int) ($result['skipped'] ?? 0);
                $this->addFlash('success', sprintf('Images ajoutées: %d (ignorées: %d).', $added, $skipped));

                if ($selectedVariant instanceof ProductVariant && $added > 0) {
                    $addedIds = $result['addedImageIds'] ?? [];
                    if (is_array($addedIds) && count($addedIds) > 0) {
                        $firstId = (int) $addedIds[0];
                        $img = $this->entityManager->getRepository(Images::class)->find($firstId);
                        if ($img instanceof Images) {
                            $selectedVariant->setPrimaryImage($img);
                            $this->entityManager->persist($selectedVariant);
                            $this->entityManager->flush();
                            $this->addFlash('success', 'Image associée à la variante (image principale).');
                        }
                    }
                }

                $errors = $result['errors'] ?? [];
                if (is_array($errors) && count($errors) > 0) {
                    $this->addFlash('warning', 'Erreurs: ' . implode(' | ', array_slice(array_values($errors), 0, 3)) . (count($errors) > 3 ? '…' : ''));
                }

                return $this->redirect($backUrl);
            }

            // mode === 'search'
            $csrf = (string) $request->request->get('_csrf', '');
            if (!$this->isCsrfTokenValid('admin_images_gpt_find_images', $csrf)) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');
                return $this->redirect($backUrl);
            }

            if (!$selectedProduct instanceof Products || $selectedProduct->getId() === null) {
                $this->addFlash('danger', 'Produit introuvable.');
            } else {
                try {
                    if ($selectedVariant instanceof ProductVariant) {
                        $variantFields = [
                            'name' => $selectedVariant->getName(),
                            'slug' => $selectedVariant->getSlug(),
                            'sku' => $selectedVariant->getSku(),
                            'barcode' => $selectedVariant->getBarcode(),
                            'color' => $selectedVariant->getColor(),
                            'colorCode' => $selectedVariant->getColorCode(),
                            'size' => $selectedVariant->getSize(),
                            'lensWidthMm' => $selectedVariant->getLensWidthMm(),
                            'bridgeWidthMm' => $selectedVariant->getBridgeWidthMm(),
                            'templeLengthMm' => $selectedVariant->getTempleLengthMm(),
                            'lensHeightMm' => $selectedVariant->getLensHeightMm(),
                            'price' => $selectedVariant->getPrice() !== null ? ($selectedVariant->getPrice() / 100) : null,
                            'stock' => $selectedVariant->getStock(),
                            'colorOptions' => array_keys($this->colorCatalog->choices()),
                        ];

                        $productContext = [
                            'name' => $selectedProduct->getName(),
                            'brand' => $selectedProduct->getBrand(),
                            'productType' => $selectedProduct->getProductType(),
                        ];

                        $ai = $variantSuggestService->suggestImagesOnly($variantFields, $productContext, [
                            'aggressiveness' => $aggressiveness,
                            'webSearch' => $webSearch,
                            'maxImages' => $maxImages,
                        ]);

                        $images = is_array($ai['images'] ?? null) ? $ai['images'] : [];
                        $suggestedImages = [];
                        foreach ($images as $img) {
                            if (!is_array($img)) {
                                continue;
                            }
                            $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                            if ($url === '') {
                                continue;
                            }
                            $suggestedImages[] = [
                                'url' => $url,
                                'label' => isset($img['label']) && is_string($img['label']) ? $img['label'] : null,
                            ];
                            if (count($suggestedImages) >= $maxImages) {
                                break;
                            }
                        }

                        $sources = is_array($ai['sources'] ?? null) ? $ai['sources'] : [];
                        $notes = is_array($ai['notes'] ?? null) ? $ai['notes'] : [];
                    } else {
                        $fields = [
                            'name' => $selectedProduct->getName(),
                            'description' => $selectedProduct->getDescription(),
                            'brand' => $selectedProduct->getBrand(),
                            'productType' => $selectedProduct->getProductType(),
                            'colorOptions' => array_keys($this->colorCatalog->choices()),
                        ];

                        $ai = $productSuggestService->suggestImagesOnly($fields, [
                            'aggressiveness' => $aggressiveness,
                            'webSearch' => $webSearch,
                            'maxImages' => $maxImages,
                        ]);

                        $images = is_array($ai['images'] ?? null) ? $ai['images'] : [];
                        $suggestedImages = [];
                        foreach ($images as $img) {
                            if (!is_array($img)) {
                                continue;
                            }
                            $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                            if ($url === '') {
                                continue;
                            }
                            $suggestedImages[] = [
                                'url' => $url,
                                'label' => isset($img['label']) && is_string($img['label']) ? $img['label'] : null,
                            ];
                            if (count($suggestedImages) >= $maxImages) {
                                break;
                            }
                        }

                        $sources = is_array($ai['sources'] ?? null) ? $ai['sources'] : [];
                        $notes = is_array($ai['notes'] ?? null) ? $ai['notes'] : [];
                    }
                } catch (\Throwable $e) {
                    $this->addFlash('danger', 'IA a échoué: ' . $e->getMessage());
                }
            }

            // Stage local previews to avoid hotlink blocks in the browser.
            if (is_array($suggestedImages) && count($suggestedImages) > 0) {
                $stageItems = [];
                foreach ($suggestedImages as $img) {
                    if (!is_array($img)) {
                        continue;
                    }
                    $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                    if ($url === '') {
                        continue;
                    }
                    $stageItems[] = ['url' => $url, 'colorTag' => null];
                    if (count($stageItems) >= $maxImages) {
                        break;
                    }
                }

                if (count($stageItems) > 0) {
                    try {
                        $staged = $imageImportService->downloadImagesToUploadsWithMeta($stageItems);
                        $previewStagingErrors = is_array($staged['errors'] ?? null) ? $staged['errors'] : [];

                        $byUrl = [];
                        $downloaded = is_array($staged['downloaded'] ?? null) ? $staged['downloaded'] : [];
                        foreach ($downloaded as $row) {
                            if (!is_array($row)) {
                                continue;
                            }
                            $sourceUrl = isset($row['sourceUrl']) && is_string($row['sourceUrl']) ? $row['sourceUrl'] : '';
                            $fileName = isset($row['fileName']) && is_string($row['fileName']) ? $row['fileName'] : '';
                            if ($sourceUrl !== '' && $fileName !== '') {
                                $byUrl[$sourceUrl] = $fileName;
                            }
                        }

                        $enhanced = [];
                        foreach ($suggestedImages as $img) {
                            if (!is_array($img)) {
                                continue;
                            }
                            $url = isset($img['url']) && is_string($img['url']) ? trim($img['url']) : '';
                            if ($url === '') {
                                continue;
                            }

                            $fileName = $byUrl[$url] ?? null;
                            if (is_string($fileName) && $fileName !== '') {
                                $img['previewLocalPath'] = 'assets/uploads/' . $fileName;
                            }
                            $enhanced[] = $img;
                        }
                        $suggestedImages = $enhanced;
                    } catch (\Throwable $e) {
                        $previewStagingErrors = ['Prévisualisation impossible: ' . $e->getMessage()];
                    }
                }
            }
        }

        return $this->render('admin/images/gpt_find_images.html.twig', [
            'products' => $products,
            'variants' => $variants,
            'selectedProduct' => $selectedProduct,
            'selectedVariant' => $selectedVariant,
            'aggressiveness' => $aggressiveness,
            'webSearch' => $webSearch,
            'suggestedImages' => $suggestedImages,
            'sources' => $sources,
            'notes' => $notes,
            'previewStagingErrors' => $previewStagingErrors,
            'backUrl' => $backUrl,
            'maxImages' => $maxImages,
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

    public function gptColorTag(AdminContext $context, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $imageId = (int) $request->query->get('entityId', 0);
        $entity = $imageId > 0 ? $this->entityManager->getRepository(Images::class)->find($imageId) : null;
        if (!$entity instanceof Images || $entity->getId() === null) {
            $this->addFlash('danger', 'Image introuvable.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        if (is_string($entity->getColorTag()) && trim($entity->getColorTag()) !== '') {
            $this->addFlash('info', 'colorTag déjà renseigné: aucune modification.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        try {
            $allowedColors = array_keys($this->colorCatalog->choices());
            $result = $this->imageColorTagService->suggestColorTag($entity, $allowedColors);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'GPT a échoué: ' . $e->getMessage());
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $colorTag = $result['colorTag'] ?? null;
        if (!is_string($colorTag) || trim($colorTag) === '') {
            $this->addFlash('warning', 'GPT n\'a pas pu proposer un colorTag (incertain).');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $entity->setColorTag($colorTag);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $label = $this->colorCatalog->labelFor($colorTag) ?? $colorTag;
        $this->addFlash('success', 'colorTag proposé: ' . $label . '.');

        return $this->redirect($context->getReferrer() ?? '/admin');
    }

    public function assignToVariantForm(
        AdminContext $context,
        Request $request,
        ProductVariantRepository $variantRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $backUrl = (string) ($context->getReferrer() ?? '/admin');
        $backUrlFromForm = $request->request->get('backUrl');
        if (is_string($backUrlFromForm) && trim($backUrlFromForm) !== '') {
            $backUrl = $backUrlFromForm;
        }

        $imageId = (int) $request->query->get('entityId', $request->request->get('imageId', 0));
        $image = $imageId > 0 ? $this->entityManager->getRepository(Images::class)->find($imageId) : null;
        if (!$image instanceof Images || $image->getId() === null) {
            $this->addFlash('danger', 'Image introuvable.');
            return $this->redirect($backUrl);
        }

        $product = $image->getProducts();
        if (!$product instanceof Products || $product->getId() === null) {
            $this->addFlash('danger', 'Produit introuvable pour cette image.');
            return $this->redirect($backUrl);
        }

        $variantsAll = $variantRepository->findBy(['products' => $product], ['id' => 'DESC'], 300);
        $variants = [];
        foreach ($variantsAll as $v) {
            if (!$v instanceof ProductVariant) {
                continue;
            }
            if ($v->getName() === 'Variante par défaut') {
                continue;
            }
            $variants[] = $v;
        }

        $currentVariantId = null;
        foreach ($variants as $v) {
            $primary = $v->getPrimaryImage();
            if ($primary instanceof Images && $primary->getId() === $image->getId()) {
                $currentVariantId = $v->getId();
                break;
            }
        }

        if ($request->isMethod('POST')) {
            $csrf = (string) $request->request->get('_csrf', '');
            if (!$this->isCsrfTokenValid('admin_images_assign_to_variant', $csrf)) {
                $this->addFlash('danger', 'Jeton CSRF invalide.');
                return $this->redirect($backUrl);
            }

            $variantId = (int) $request->request->get('variantId', 0);
            $mode = (string) $request->request->get('mode', 'link');

            $variant = $variantId > 0 ? $variantRepository->find($variantId) : null;
            if (!$variant instanceof ProductVariant || $variant->getId() === null) {
                $this->addFlash('warning', 'Variante introuvable.');
                return $this->redirect($context->getRequest()->getUri());
            }

            if ($variant->getProducts() === null || $variant->getProducts()->getId() !== $product->getId()) {
                $this->addFlash('warning', 'Cette variante ne correspond pas au même produit.');
                return $this->redirect($context->getRequest()->getUri());
            }

            if ($mode === 'unlink') {
                $primary = $variant->getPrimaryImage();
                if ($primary instanceof Images && $primary->getId() === $image->getId()) {
                    $variant->setPrimaryImage(null);
                    $this->entityManager->persist($variant);
                    $this->entityManager->flush();
                    $this->addFlash('success', 'Image dissociée de la variante.');
                } else {
                    $this->addFlash('info', 'Aucune association à retirer pour cette variante.');
                }
            } else {
                $variant->setPrimaryImage($image);
                $this->entityManager->persist($variant);
                $this->entityManager->flush();
                $this->addFlash('success', 'Image associée à la variante (image principale).');
            }

            return $this->redirect($backUrl);
        }

        return $this->render('admin/images/assign_to_variant.html.twig', [
            'image' => $image,
            'product' => $product,
            'variants' => $variants,
            'currentVariantId' => $currentVariantId,
            'backUrl' => $backUrl,
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        $isEmbedded = in_array($pageName, ['embedded_new', 'embedded_edit'], true);

        yield IdField::new('id')->onlyOnIndex();

        yield ImageField::new('name', 'Fichier')
            ->setBasePath('assets/uploads/')
            ->setUploadDir('public/assets/uploads/')
            ->setUploadedFileNamePattern('[randomhash].[extension]')
            ->setFormTypeOption('constraints', [
                new Image([
                    'maxSize' => '6M',
                    'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                    'maxWidth' => 8000,
                    'maxHeight' => 8000,
                ]),
            ])
            ->setHelp('Importe une image (PNG/JPG/WebP).');

        yield ChoiceField::new('colorTag', 'Couleur (colorTag)')
            ->setRequired(false)
            ->setChoices($this->colorCatalog->choices())
            ->renderExpanded(false)
            ->setHelp('Optionnel. Utilisé pour relier l\'image à une variante (couleur).');

        yield TextField::new('sourceUrl', 'URL source')
            ->setRequired(false)
            ->setHelp('Optionnel. Source de l\'image (si importée).');

        if (!$isEmbedded) {
            yield AssociationField::new('products', 'Produit');
        }
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Images;
use App\Entity\Products;
use App\Entity\ProductVariant;
use App\Repository\CategoriesRepository;
use App\Service\Ai\OpenAiProductSuggestService;
use App\Service\Catalog\ColorCatalog;
use App\Form\Admin\ProductsBarcodeImportType;
use App\Service\ProductImport\ProductBarcodeImportService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityRemoveException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\ForbiddenActionException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\InsufficientEntityPermissionException;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Security\Permission;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\SluggerInterface;

class ProductsCrudController extends AbstractCrudController
{
    public function __construct(
        private SluggerInterface $slugger,
        private AdminUrlGenerator $adminUrlGenerator,
        private ProductBarcodeImportService $importService,
        private RequestStack $requestStack,
        private CategoriesRepository $categoriesRepository,
        private ColorCatalog $colorCatalog,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Products::class;
    }

    public function createEntity(string $entityFqcn)
    {
        /** @var Products $product */
        $product = new Products();

        // Prefill required fields so the admin can use the GPT panel on the create form
        // without being blocked by HTML required fields or entity constraints.
        $product->setName('Nouveau produit (brouillon)');
        $product->setDescription('À compléter');

        // Keep support for products without variants by creating a default variant.
        // The admin can later add/modify variants as needed.
        $defaultVariant = new ProductVariant();
        $defaultVariant->setName('Variante par défaut');
        $defaultVariant->setPrice(0);
        $defaultVariant->setStock(0);
        $product->addVariant($defaultVariant);

        $defaultCategory = $this->categoriesRepository->findOneBy([], ['id' => 'ASC']);
        if ($defaultCategory !== null) {
            $product->setCategories($defaultCategory);
        }

        return $product;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Produit')
            ->setEntityLabelInPlural('Produits')
            ->setDefaultSort(['id' => 'DESC'])
            ->setSearchFields(['name', 'brand'])
            ->overrideTemplates([
                'crud/new' => 'admin/products/crud_new.html.twig',
                'crud/edit' => 'admin/products/crud_edit.html.twig',
            ])
            ->setPageTitle('index', 'Gestion des produits');
    }

    public function configureActions(Actions $actions): Actions
    {
        $import = Action::new('import', 'Importer (code-barres)', 'fas fa-barcode')
            ->linkToCrudAction('import')
            ->createAsGlobalAction();

        $canDelete = static function ($entity): bool {
            if (!$entity instanceof Products) {
                return false;
            }

            // If the product is part of any order line, deleting it would break order history.
            return $entity->getOrdersDetails()->count() === 0;
        };

        $gptFillEmpty = Action::new('gptFillEmpty', '✨ GPT: Compléter (champs vides)', 'fas fa-magic')
            ->linkToCrudAction('gptFillEmpty');

        return $actions
            ->add(Crud::PAGE_INDEX, $import)
            ->add(Crud::PAGE_EDIT, $gptFillEmpty)
            ->add(Crud::PAGE_DETAIL, $gptFillEmpty)
            // Hide delete when there are dependent order lines.
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action->displayIf($canDelete))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn (Action $action) => $action->displayIf($canDelete))
            // Add the delete action back to the edit page (it doesn't exist there by default, so we must add it before updating).
            ->add(Crud::PAGE_EDIT, Action::DELETE)
            ->update(Crud::PAGE_EDIT, Action::DELETE, static fn (Action $action) => $action->displayIf($canDelete));
    }

    public function gptFillEmpty(AdminContext $context, OpenAiProductSuggestService $suggestService, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $entity = $context->getEntity()?->getInstance();
        if (!$entity instanceof Products) {
            $this->addFlash('danger', 'Produit introuvable.');
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $fields = [
            'name' => (string) ($entity->getName() ?? ''),
            'description' => (string) ($entity->getDescription() ?? ''),
            'brand' => $entity->getBrand(),
            'productType' => $entity->getProductType(),
            'gender' => $entity->getGender(),
            'frameShape' => $entity->getFrameShape(),
            'frameMaterial' => $entity->getFrameMaterial(),
            'frameStyle' => $entity->getFrameStyle(),
            'uvProtection' => $entity->getUvProtection(),
            'polarized' => $entity->isPolarized(),
            'prescriptionAvailable' => $entity->isPrescriptionAvailable(),
            // Help the model stay consistent with our catalog.
            'colorOptions' => array_keys($this->colorCatalog->choices()),
        ];

        try {
            $result = $suggestService->suggestFieldsOnly($fields, [
                'aggressiveness' => 'low',
                'webSearch' => false,
            ]);
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'GPT a échoué: ' . $e->getMessage());
            return $this->redirect($context->getReferrer() ?? '/admin');
        }

        $suggested = $result['suggested'] ?? [];
        if (!is_array($suggested)) {
            $suggested = [];
        }

        $changed = 0;

        $isEmptyStr = static function (?string $v): bool {
            return $v === null || trim($v) === '';
        };
        $clean = static function (mixed $v): ?string {
            return is_string($v) && trim($v) !== '' ? trim($v) : null;
        };

        // Name: only replace placeholder.
        $currentName = $entity->getName();
        $nameSuggestion = $clean($suggested['name'] ?? null);
        if (($currentName === null || trim($currentName) === '' || trim($currentName) === 'Nouveau produit (brouillon)') && $nameSuggestion !== null) {
            $entity->setName($nameSuggestion);
            $changed++;
        }

        // Description: replace placeholder or empty.
        $currentDesc = $entity->getDescription();
        $descSuggestion = $clean($suggested['description'] ?? null);
        if (($currentDesc === null || trim($currentDesc) === '' || trim($currentDesc) === 'À compléter') && $descSuggestion !== null) {
            $entity->setDescription($descSuggestion);
            $changed++;
        }

        $brandSuggestion = $clean($suggested['brand'] ?? null);
        if ($isEmptyStr($entity->getBrand()) && $brandSuggestion !== null) {
            $entity->setBrand($brandSuggestion);
            $changed++;
        }

        $ptSuggestion = $clean($suggested['productType'] ?? null);
        if ($isEmptyStr($entity->getProductType()) && $ptSuggestion !== null) {
            $entity->setProductType($ptSuggestion);
            $changed++;
        }

        $genderSuggestion = $clean($suggested['gender'] ?? null);
        if ($isEmptyStr($entity->getGender()) && $genderSuggestion !== null) {
            $entity->setGender($genderSuggestion);
            $changed++;
        }

        $fsSuggestion = $clean($suggested['frameShape'] ?? null);
        if ($isEmptyStr($entity->getFrameShape()) && $fsSuggestion !== null) {
            $entity->setFrameShape($fsSuggestion);
            $changed++;
        }

        $fmSuggestion = $clean($suggested['frameMaterial'] ?? null);
        if ($isEmptyStr($entity->getFrameMaterial()) && $fmSuggestion !== null) {
            $entity->setFrameMaterial($fmSuggestion);
            $changed++;
        }

        $fstSuggestion = $clean($suggested['frameStyle'] ?? null);
        if ($isEmptyStr($entity->getFrameStyle()) && $fstSuggestion !== null) {
            $entity->setFrameStyle($fstSuggestion);
            $changed++;
        }

        $uvSuggestion = $clean($suggested['uvProtection'] ?? null);
        if ($isEmptyStr($entity->getUvProtection()) && $uvSuggestion !== null) {
            $entity->setUvProtection($uvSuggestion);
            $changed++;
        }

        if ($changed > 0) {
            $this->ensureSlug($entity);
            $this->ensureVariantSlugs($entity);
            $entityManager->persist($entity);
            $entityManager->flush();
            $this->addFlash('success', 'Produit complété (champs vides): ' . $changed . ' champ(s).');
        } else {
            $this->addFlash('info', 'Aucun champ vide complété (rien à faire).');
        }

        return $this->redirect($context->getReferrer() ?? '/admin');
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Products && $entityInstance->getOrdersDetails()->count() > 0) {
            throw new EntityRemoveException([
                'entity_name' => 'Produit',
                'message' => 'Impossible de supprimer ce produit car il est associé à une ou plusieurs commandes. ' .
                    'Pour le retirer du site, mettez le stock à 0 (ou désactivez-le si vous avez ce champ).',
            ]);
        }

        parent::deleteEntity($entityManager, $entityInstance);
    }

    public function batchDelete(AdminContext $context, BatchActionDto $batchActionDto): Response
    {
        if (!$this->isCsrfTokenValid('ea-batch-action-'.Action::BATCH_DELETE, $batchActionDto->getCsrfToken())) {
            return $this->redirectToRoute($context->getDashboardRouteName());
        }

        $entityManager = $this->container->get('doctrine')->getManagerForClass($batchActionDto->getEntityFqcn());
        $repository = $entityManager->getRepository($batchActionDto->getEntityFqcn());

        $deleted = 0;
        $skipped = 0;

        foreach ($batchActionDto->getEntityIds() as $entityId) {
            $entityInstance = $repository->find($entityId);
            if (!$entityInstance) {
                continue;
            }

            $entityDto = $context->getEntity()->newWithInstance($entityInstance);
            if (!$this->isGranted(Permission::EA_EXECUTE_ACTION, ['action' => Action::DELETE, 'entity' => $entityDto, 'entityFqcn' => $context->getEntity()->getFqcn()])) {
                throw new ForbiddenActionException($context);
            }

            if (!$entityDto->isAccessible()) {
                throw new InsufficientEntityPermissionException($context);
            }

            // Safety: never delete products referenced by orders (keep order history intact)
            if ($entityInstance instanceof Products && $entityInstance->getOrdersDetails()->count() > 0) {
                $skipped++;
                continue;
            }

            try {
                $this->deleteEntity($entityManager, $entityInstance);
                $deleted++;
            } catch (EntityRemoveException) {
                $skipped++;
            } catch (ForeignKeyConstraintViolationException) {
                $skipped++;
            }
        }

        if ($deleted > 0) {
            $this->addFlash('success', sprintf('%d produit(s) supprimé(s).', $deleted));
        }
        if ($skipped > 0) {
            $this->addFlash('warning', sprintf('%d produit(s) ignoré(s) (liés à des commandes ou suppression impossible).', $skipped));
        }

        return $this->redirect($batchActionDto->getReferrerUrl());
    }

    public function import(AdminContext $context, Request $request): Response
    {
        $form = $this->createForm(ProductsBarcodeImportType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{barcode:string,categories:object,price:mixed,stock:int,updateIfExists?:bool} $data */
            $data = $form->getData();

            $externalId = (string) $form->get('externalId')->getData();
            $externalSource = (string) $form->get('externalSource')->getData();
            $barcode = (string) ($data['barcode'] ?? '');

            $price = $data['price'] ?? 0;
            $priceCents = (int) round(((float) $price) * 100);

            try {
                if (trim($barcode) !== '') {
                    $result = $this->importService->import(
                        barcode: $barcode,
                        category: $data['categories'],
                        priceCents: $priceCents,
                        stock: (int) ($data['stock'] ?? 0),
                        updateIfExists: (bool) ($data['updateIfExists'] ?? false),
                    );
                } elseif (trim($externalId) !== '' && strtolower(trim($externalSource)) === 'wikidata') {
                    $result = $this->importService->importFromWikidata(
                        qid: $externalId,
                        category: $data['categories'],
                        priceCents: $priceCents,
                        stock: (int) ($data['stock'] ?? 0),
                        updateIfExists: (bool) ($data['updateIfExists'] ?? false),
                    );
                } else {
                    throw new \InvalidArgumentException('Veuillez saisir un code-barres ou sélectionner un produit depuis la recherche.');
                }
            } catch (\Throwable $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->render('admin/products_import.html.twig', [
                    'form' => $form->createView(),
                    'ea' => $context,
                ]);
            }

            $this->addFlash('success', $result['message']);

            $product = $result['product'];
            $url = $this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::EDIT)
                ->setEntityId((string) $product->getId())
                ->generateUrl();

            return $this->redirect($url);
        }

        return $this->render('admin/products_import.html.twig', [
            'form' => $form->createView(),
            'ea' => $context,
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('name', 'Nom');

        yield CollectionField::new('images', 'Images')
            ->onlyOnIndex()
            ->setSortable(false)
            ->setTemplatePath('admin/field/product_images_thumbnails.html.twig');

        yield TextField::new('slug', 'Slug')
            ->hideOnIndex()
            ->setHelp('Laisser vide pour générer automatiquement depuis le nom');

        yield AssociationField::new('categories', 'Catégorie');

        yield AssociationField::new('secondaryCategories', 'Catégories secondaires')
            ->hideOnIndex()
            ->setRequired(false)
            ->setFormTypeOption('by_reference', false)
            ->setHelp('Permet d\'associer le produit à plusieurs catégories (sans changer la catégorie principale).');

        yield ChoiceField::new('productType', 'Type de produit')
            ->hideOnIndex()
            ->setRequired(false)
            ->setChoices([
                'Monture optique' => 'optical_frame',
                'Lunettes de soleil' => 'sunglasses',
                'Accessoire' => 'accessory',
            ]);

        yield ChoiceField::new('gender', 'Genre')
            ->hideOnIndex()
            ->setRequired(false)
            ->setChoices([
                'Unisexe' => 'unisex',
                'Homme' => 'men',
                'Femme' => 'women',
                'Enfant' => 'kids',
            ]);

        yield TextField::new('frameShape', 'Forme (monture)')
            ->hideOnIndex()
            ->setRequired(false);

        yield TextField::new('frameMaterial', 'Matière (monture)')
            ->hideOnIndex()
            ->setRequired(false);

        yield TextField::new('frameStyle', 'Style (monture)')
            ->hideOnIndex()
            ->setRequired(false);

        yield BooleanField::new('polarized', 'Polarisées')
            ->hideOnIndex()
            ->setRequired(false);

        yield BooleanField::new('prescriptionAvailable', 'Compatible correction')
            ->hideOnIndex()
            ->setRequired(false);

        yield TextField::new('uvProtection', 'Protection UV')
            ->hideOnIndex()
            ->setRequired(false);

        yield TextEditorField::new('description', 'Description')->hideOnIndex();

        yield TextField::new('brand', 'Marque')
            ->hideOnIndex()
            ->setRequired(false);

        // Gestion des variantes directement depuis le produit
        yield CollectionField::new('variants', 'Déclinaisons')
            ->onlyOnForms()
            ->setEntryIsComplex(true)
            ->useEntryCrudForm(ProductVariantCrudController::class, 'embedded_new', 'embedded_edit')
            ->setFormTypeOption('by_reference', false)
            ->setFormTypeOption('allow_delete', false)
            ->setHelp('Optionnel. Utilisez les déclinaisons pour gérer stock/prix/couleur/taille.');

        // Gestion des images directement depuis le produit
        yield CollectionField::new('images', 'Images')
            ->onlyOnForms()
            ->setEntryIsComplex(true)
            ->useEntryCrudForm(ImagesCrudController::class, 'embedded_new', 'embedded_edit')
            ->setFormTypeOption('allow_delete', false)
            ->setHelp('Ajoute une ou plusieurs images en important les fichiers.');

        // Champs techniques / relations secondaires
        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnDetail();
        yield AssociationField::new('images', 'Images')->onlyOnDetail();
        yield AssociationField::new('ordersDetails', 'Lignes de commande')->onlyOnDetail();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Products) {
            $this->ensureSlug($entityInstance);
            $this->ensureVariantSlugs($entityInstance);

            $request = $this->requestStack->getCurrentRequest();
            $token = $request?->request->get('gpt_staged_images_token');
            if (is_string($token)) {
                $token = trim($token);
            } else {
                $token = '';
            }

            if ($token !== '' && $request && $request->hasSession()) {
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
                        $entityInstance->addImage($image);
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
        if ($entityInstance instanceof Products) {
            $this->ensureSlug($entityInstance);
            $this->ensureVariantSlugs($entityInstance);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function ensureVariantSlugs(Products $product): void
    {
        foreach ($product->getVariants() as $variant) {
            if (!$variant instanceof ProductVariant) {
                continue;
            }

            if ($variant->getProducts() !== $product) {
                $variant->setProducts($product);
            }

            $slug = $variant->getSlug();
            if ($slug !== null && trim($slug) !== '') {
                continue;
            }

            $name = $variant->getName();
            if ($name === null || trim($name) === '') {
                continue;
            }

            $variant->setSlug($this->slugger->slug($name)->lower()->toString());
        }
    }

    private function ensureSlug(Products $product): void
    {
        $slug = $product->getSlug();
        if ($slug !== null && trim($slug) !== '') {
            return;
        }

        $name = $product->getName();
        if ($name === null || trim($name) === '') {
            return;
        }

        $product->setSlug($this->slugger->slug($name)->lower()->toString());
    }
}

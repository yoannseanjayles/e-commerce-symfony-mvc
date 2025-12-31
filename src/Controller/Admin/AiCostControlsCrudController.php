<?php

namespace App\Controller\Admin;

use App\Entity\SiteSettings;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

final class AiCostControlsCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly SiteSettingsRepository $settingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return SiteSettings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('AI Cost Controls')
            ->setEntityLabelInPlural('AI Cost Controls')
            ->setDefaultSort(['id' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::EDIT)
            ->disable(Action::BATCH_DELETE);
    }

    public function index(AdminContext $context): Response
    {
        $settings = $this->settingsRepository->findSettings();
        if (!$settings) {
            $settings = new SiteSettings();
            $this->entityManager->persist($settings);
            $this->entityManager->flush();
        }

        $url = $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId((string) $settings->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            FormField::addPanel('IA — Garde-fous (anti runaway spend)')->setIcon('fa fa-robot'),

            ChoiceField::new('aiGuardEnabledOverride', 'Rate limit')
                ->setChoices([
                    'Auto (env)' => null,
                    'Activer' => true,
                    'Désactiver' => false,
                ])
                ->setHelp('Auto = utilise AI_GUARD_ENABLED. Désactiver = aucun blocage 429.'),

            IntegerField::new('aiMaxPerMinuteOverride', 'Max / minute')
                ->setHelp('Auto = AI_MAX_PER_MINUTE (défaut 20).'),
            IntegerField::new('aiMaxPerDayOverride', 'Max / jour')
                ->setHelp('Auto = AI_MAX_PER_DAY (défaut 400).'),
            IntegerField::new('aiMaxWebSearchPerDayOverride', 'Max web_search / jour')
                ->setHelp('Auto = AI_MAX_WEB_SEARCH_PER_DAY (défaut 80).'),

            FormField::addPanel('IA — Cache (évite les doubles appels)')->setIcon('fa fa-bolt'),

            ChoiceField::new('aiCacheEnabledOverride', 'Cache 5 min')
                ->setChoices([
                    'Auto (env)' => null,
                    'Activer' => true,
                    'Désactiver' => false,
                ])
                ->setHelp('Auto = utilise AI_CACHE_ENABLED. Le cache évite les doubles clics/retry.'),

            IntegerField::new('aiCacheTtlSecondsOverride', 'TTL cache (secondes)')
                ->setHelp('Auto = AI_CACHE_TTL_SECONDS (défaut 300).'),
        ];
    }
}

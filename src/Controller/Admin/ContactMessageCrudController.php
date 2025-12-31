<?php

namespace App\Controller\Admin;

use App\Entity\ContactMessage;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ContactMessageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ContactMessage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Message de contact')
            ->setEntityLabelInPlural('Messages de contact')
            // CreatedAtTrait uses the mapped field name "created_at".
            ->setDefaultSort(['created_at' => 'DESC'])
            ->setPageTitle('index', 'Messages de contact')
            ->setPageTitle('detail', fn (ContactMessage $message) => sprintf('Message de %s', $message->getName()));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::DELETE, 'ROLE_ADMIN');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('name', 'Nom');
        yield EmailField::new('email', 'Email');
        yield TextField::new('subject', 'Sujet')->hideOnIndex();
        yield TextareaField::new('message', 'Message')
            ->renderAsHtml()
            ->setMaxLength(200)
            ->onlyOnIndex();
        yield TextareaField::new('message', 'Message')
            ->hideOnIndex();
        yield DateTimeField::new('createdAt', 'Date d\'envoi')
            ->setSortable(false)
            ->setFormat('dd/MM/yyyy HH:mm');
        yield BooleanField::new('isRead', 'Lu');
    }
}

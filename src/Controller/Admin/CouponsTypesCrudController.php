<?php

namespace App\Controller\Admin;

use App\Entity\CouponsTypes;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

class CouponsTypesCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return CouponsTypes::class;
    }
}

<?php

namespace App\DataFixtures;

use App\Entity\Users;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // Check if admin already exists
        $adminExists = $manager->getRepository(Users::class)->findOneBy(['email' => 'admin@admin.com']);
        
        if ($adminExists) {
            return; // Admin already exists, skip
        }

        // Create admin user
        $admin = new Users();
        $admin->setEmail('admin@admin.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setFirstname('Super');
        $admin->setLastname('Admin');
        $admin->setAddress('Admin Address');
        $admin->setZipcode('00000');
        $admin->setCity('Admin City');
        
        // Hash the password - CHANGE THIS PASSWORD AFTER FIRST LOGIN!
        $hashedPassword = $this->passwordHasher->hashPassword($admin, 'admin123');
        $admin->setPassword($hashedPassword);

        $manager->persist($admin);
        $manager->flush();
    }

    public static function getGroups(): array
    {
        return ['admin'];
    }
}

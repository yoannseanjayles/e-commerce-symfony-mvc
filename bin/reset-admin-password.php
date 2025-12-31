#!/usr/bin/env php
<?php

use App\Entity\Users;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new \App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();

$entityManager = $container->get('doctrine')->getManager();

echo "Looking for admin user...\n";
$admin = $entityManager->getRepository(Users::class)->findOneBy(['email' => 'admin@admin.com']);

if (!$admin) {
    echo "❌ Admin user not found. Creating new admin...\n";
    
    $admin = new Users();
    $admin->setEmail('admin@admin.com');
    $admin->setRoles(['ROLE_ADMIN']);
    $admin->setFirstname('Super');
    $admin->setLastname('Admin');
    $admin->setAddress('Admin Address');
    $admin->setZipcode('00000');
    $admin->setCity('Admin City');
    
    $entityManager->persist($admin);
}

echo "Generating new password hash...\n";

// Generate a fresh bcrypt hash for "password"
$newHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 13]);
echo "New hash generated: " . substr($newHash, 0, 30) . "...\n";

$admin->setPassword($newHash);
$entityManager->flush();

echo "✅ Admin password updated successfully!\n";
echo "Email: admin@admin.com\n";
echo "Password: password\n";
echo "\nYou can now login at: /connexion\n";

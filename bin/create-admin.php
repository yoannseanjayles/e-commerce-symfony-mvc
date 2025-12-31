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

// Check if admin exists
$admin = $entityManager->getRepository(Users::class)->findOneBy(['email' => 'admin@admin.com']);

if (!$admin) {
    echo "Creating admin user...\n";
    
    $admin = new Users();
    $admin->setEmail('admin@admin.com');
    $admin->setRoles(['ROLE_ADMIN']);
    $admin->setFirstname('Super');
    $admin->setLastname('Admin');
    $admin->setAddress('Admin Address');
    $admin->setZipcode('00000');
    $admin->setCity('Admin City');
    
    // Use pre-hashed password (bcrypt for "password")
    $hashedPassword = '$2y$13$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $admin->setPassword($hashedPassword);
    
    $entityManager->persist($admin);
    $entityManager->flush();
    
    echo "Admin user created successfully!\n";
} else {
    echo "Admin user already exists.\n";
}
